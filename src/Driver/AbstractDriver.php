<?php

declare(strict_types=1);

namespace Kode\Session\Driver;

use Kode\Session\Contract\Driver;
use Kode\Session\Support\Encrypter;
use Kode\Session\Support\SessionId;

/**
 * 驱动基类
 * 提供驱动公共功能的默认实现
 *
 * @author kode
 */
abstract class AbstractDriver implements Driver
{
    /**
     * 数据包装键：真实值
     */
    protected const string FIELD_DATA = 'data';

    /**
     * 数据包装键：过期时间戳（0 表示不过期）
     */
    protected const string FIELD_EXPIRE = 'expire';

    /**
     * 配置
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * 存储路径（文件类驱动使用）
     */
    protected string $path;

    /**
     * 键前缀
     */
    protected string $prefix;

    /**
     * 默认生命周期（秒），0 表示不主动过期
     */
    protected int $defaultLifetime;

    /**
     * 是否启用透明加密（写入存储层前对值加密）
     */
    protected bool $encrypted = false;

    /**
     * 加解密器（启用加密时实例化）
     */
    protected ?Encrypter $encrypter = null;

    /**
     * 构造函数
     *
     * @param array<string, mixed> $config 配置数组
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->path = (string) ($config['path'] ?? sys_get_temp_dir());
        $this->prefix = (string) ($config['prefix'] ?? 'kode_session_');
        $this->defaultLifetime = (int) ($config['lifetime'] ?? 0);

        if (!empty($config['encrypted'])) {
            $secret = (string) ($config['secret'] ?? '');
            $this->encrypted = true;
            $this->encrypter = new Encrypter($secret);
        }
    }

    /**
     * 批量写入（默认逐条落地，具体驱动可覆写为原子实现）
     *
     * @param string               $id       Session ID
     * @param array<string, mixed> $values   键值对
     * @param int                  $lifetime 生命周期
     */
    public function setMultiple(string $id, array $values, int $lifetime = 0): bool
    {
        $ok = true;

        foreach ($values as $name => $value) {
            $ok = $this->set($id, (string) $name, $value, $lifetime) && $ok;
        }

        return $ok;
    }

    /**
     * 检查该 session 在存储中是否存在（默认按数据是否为空判断）
     *
     * @param string $id Session ID
     */
    public function exists(string $id): bool
    {
        return $this->all($id) !== [];
    }

    /**
     * 获取并删除值（默认实现）
     *
     * @param string $id      Session ID
     * @param string $name    键名
     * @param mixed  $default 默认值
     */
    public function pull(string $id, string $name, mixed $default = null): mixed
    {
        $value = $this->get($id, $name, $default);
        $this->delete($id, $name);

        return $value;
    }

    /**
     * 不存在时执行回调并存储结果（默认实现）
     *
     * @param string   $id       Session ID
     * @param string   $name     键名
     * @param callable $callback 回调函数
     * @param int      $lifetime 生命周期
     */
    public function remember(string $id, string $name, callable $callback, int $lifetime = 0): mixed
    {
        if ($this->has($id, $name)) {
            return $this->get($id, $name);
        }

        $value = $callback();
        $this->set($id, $name, $value, $lifetime);

        return $value;
    }

    /**
     * 迁移数据到新 ID（默认实现：读出 -> 批量写入 -> 可选删除）
     *
     * @param string $fromId 原 Session ID
     * @param string $toId   新 Session ID
     * @param bool   $delete 是否删除原数据
     */
    public function migrate(string $fromId, string $toId, bool $delete = true): bool
    {
        if ($fromId === $toId) {
            return true;
        }

        $data = $this->all($fromId);

        if ($data !== []) {
            $this->setMultiple($toId, $data, $this->defaultLifetime);
        }

        if ($delete) {
            $this->destroy($fromId);
        }

        return true;
    }

    /**
     * 生成 session ID
     */
    public function generateId(): string
    {
        return SessionId::generate();
    }

    /**
     * 获取分布式锁（默认无锁实现）
     *
     * @param string   $id      Session ID
     * @param int|null $timeout 超时时间
     */
    public function acquireLock(string $id, ?int $timeout = null): bool
    {
        return true;
    }

    /**
     * 释放分布式锁（默认无锁实现）
     *
     * @param string $id Session ID
     */
    public function releaseLock(string $id): bool
    {
        return true;
    }

    /**
     * 校验并返回合法 Session ID
     *
     * @param string $id Session ID
     * @throws \Kode\Session\Exception\InvalidSessionIdException
     */
    protected function validateId(string $id): string
    {
        return SessionId::assert($id);
    }

    /**
     * 包装值（附带过期信息；启用加密时 data 为密文）
     *
     * @param mixed $value    原始值
     * @param int   $lifetime 生命周期
     * @return array{data: mixed, expire: int}
     */
    protected function wrap(mixed $value, int $lifetime = 0): array
    {
        $ttl = $lifetime > 0 ? $lifetime : $this->defaultLifetime;

        return [
            self::FIELD_DATA => $this->encrypted ? $this->encrypter->encrypt($value) : $value,
            self::FIELD_EXPIRE => $ttl > 0 ? time() + $ttl : 0,
        ];
    }

    /**
     * 判断包装结构是否为本包写入的格式
     *
     * @param mixed $value 待判断值
     * @phpstan-assert-if-true array{data: mixed, expire: int} $value
     */
    protected function isWrapped(mixed $value): bool
    {
        return is_array($value)
            && array_key_exists(self::FIELD_DATA, $value)
            && array_key_exists(self::FIELD_EXPIRE, $value);
    }

    /**
     * 解包值（启用加密时自动解密；解密失败降级为默认值）
     *
     * @param mixed $value   包装值
     * @param mixed $default 默认值
     */
    protected function unwrap(mixed $value, mixed $default = null): mixed
    {
        if (!$this->isWrapped($value)) {
            return $default;
        }

        $data = $value[self::FIELD_DATA];

        if ($this->encrypted) {
            if (!is_string($data) || !Encrypter::isEncrypted($data)) {
                return $default;
            }

            $decrypted = $this->encrypter->decrypt($data);

            return $decrypted ?? $default;
        }

        return $data;
    }

    /**
     * 检查包装值是否过期
     *
     * @param mixed $value 包装值
     */
    protected function isExpired(mixed $value): bool
    {
        if (!$this->isWrapped($value)) {
            return false;
        }

        $expire = (int) $value[self::FIELD_EXPIRE];

        return $expire > 0 && time() > $expire;
    }

    /**
     * 获取 session 所有数据（子类必须实现）
     *
     * @param string $id Session ID
     * @return array<string, mixed>
     */
    abstract public function all(string $id): array;
}
