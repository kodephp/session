<?php

declare(strict_types=1);

namespace Kode\Session\Driver;

use Kode\Session\Exception\LockException;

/**
 * Cookie 驱动 - 基于 Cookie 存储 session
 * 数据存储在客户端 Cookie 中，适合轻量级场景
 *
 * 实现要点：
 * - Cookie 体积有限（通常 4KB），仅适合少量数据
 * - 可选 HMAC-SHA256 签名，防止客户端篡改（配置 secret 后启用）
 * - 写入前检查 headers_sent，避免「Cannot modify header information」致命错误
 * - 统一复用 AbstractDriver 的 wrap/unwrap/isExpired
 *
 * @author kode
 */
class CookieDriver extends AbstractDriver
{
    /**
     * Cookie 数据（值为已 wrap 的内部结构）
     *
     * @var array<string, array{data: mixed, expire: int}>
     */
    protected array $data = [];

    /**
     * 是否已加载
     */
    protected bool $loaded = false;

    /**
     * 是否启用签名
     */
    protected bool $signing;

    /**
     * 签名密钥
     */
    protected string $secret;

    /**
     * Cookie 名称
     */
    protected string $cookieName;

    /**
     * 最近一次写出的 Cookie 值（便于测试与排查）
     */
    protected string $lastCookieValue = '';

    /**
     * 构造函数
     *
     * @param array<string, mixed> $config 配置数组
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->cookieName = (string) ($config['name'] ?? 'kode_session');
        $this->secret = (string) ($config['secret'] ?? '');
        $this->signing = $this->secret !== '';

        $this->load();
    }

    /**
     * 从 Cookie 加载数据（支持测试注入 cookie_data）
     */
    protected function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->data = [];

        $cookieData = $this->config['cookie_data'][$this->cookieName]
            ?? $_COOKIE[$this->cookieName]
            ?? null;

        if (!is_string($cookieData)) {
            return;
        }

        $decoded = base64_decode($cookieData, true);

        if ($decoded === false) {
            return;
        }

        $payload = json_decode($decoded, true);

        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            return;
        }

        if ($this->signing) {
            $sig = $payload['sig'] ?? null;
            $expected = $this->sign($payload['data'], $payload['expire'] ?? 0, $payload['id'] ?? '');

            if (!is_string($sig) || !hash_equals($expected, $sig)) {
                // 签名校验失败：视为被篡改，丢弃整段会话
                $this->data = [];

                return;
            }
        }

        $data = $payload['data'];

        foreach ($data as $key => $value) {
            if ($this->isWrapped($value)) {
                $this->data[$key] = $value;
            }
        }
    }

    /**
     * 计算签名
     *
     * @param array<string, mixed> $data
     */
    protected function sign(array $data, int $expire, string $id): string
    {
        $material = json_encode([$id, $expire, $data], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $material, $this->secret);
    }

    /**
     * 保存数据到 Cookie（headers 已发送则跳过）
     *
     * @param string $id Session ID
     */
    protected function save(string $id): bool
    {
        if (headers_sent()) {
            return true;
        }

        $this->lastCookieValue = $this->buildCookieValue($id);

        return setcookie($this->cookieName, $this->lastCookieValue, [
            'expires' => $this->defaultLifetime > 0 ? time() + $this->defaultLifetime : 0,
            'path' => $this->config['path'] ?? '/',
            'domain' => $this->config['domain'] ?? null,
            'secure' => (bool) ($this->config['secure'] ?? false),
            'httponly' => (bool) ($this->config['http_only'] ?? true),
            'samesite' => $this->config['samesite'] ?? 'Lax',
        ]) !== false;
    }

    /**
     * 构造 Cookie 值（base64(json([...]))，可选 HMAC 签名）
     *
     * @param string $id Session ID
     */
    public function buildCookieValue(string $id): string
    {
        $expire = $this->defaultLifetime > 0 ? time() + $this->defaultLifetime : 0;

        $data = [
            'id' => $id,
            'expire' => $expire,
            'data' => $this->data,
        ];

        if ($this->signing) {
            $data['sig'] = $this->sign($data['data'], $expire, $id);
        }

        return base64_encode(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * 获取最近一次写出的 Cookie 值
     */
    public function getLastCookieValue(): string
    {
        return $this->lastCookieValue;
    }

    /**
     * 获取 session 值
     *
     * @param string $id      Session ID
     * @param string $name    键名
     * @param mixed  $default 默认值
     */
    #[\Override]
    public function get(string $id, string $name, mixed $default = null): mixed
    {
        $this->load();

        if (!isset($this->data[$name])) {
            return $default;
        }

        if ($this->isExpired($this->data[$name])) {
            $this->delete($id, $name);

            return $default;
        }

        return $this->unwrap($this->data[$name], $default);
    }

    /**
     * 设置 session 值
     *
     * @param string $id       Session ID
     * @param string $name     键名
     * @param mixed  $value     值
     * @param int    $lifetime  生命周期（秒）
     */
    #[\Override]
    public function set(string $id, string $name, mixed $value, int $lifetime = 0): bool
    {
        $this->load();
        $this->data[$name] = $this->wrap($value, $lifetime);

        return $this->save($id);
    }

    /**
     * 删除 session 值
     *
     * @param string $id   Session ID
     * @param string $name 键名
     */
    #[\Override]
    public function delete(string $id, string $name): bool
    {
        $this->load();

        if (!isset($this->data[$name])) {
            return true;
        }

        unset($this->data[$name]);

        return $this->save($id);
    }

    /**
     * 检查键是否存在（值为 null 也算存在）
     *
     * @param string $id   Session ID
     * @param string $name 键名
     */
    #[\Override]
    public function has(string $id, string $name): bool
    {
        $this->load();

        if (!isset($this->data[$name])) {
            return false;
        }

        return !$this->isExpired($this->data[$name]);
    }

    /**
     * 清空指定 session 的所有数据（清除 Cookie）
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function clear(string $id): bool
    {
        $this->data = [];

        if (headers_sent()) {
            return true;
        }

        return setcookie($this->cookieName, '', [
            'expires' => time() - 3600,
            'path' => $this->config['path'] ?? '/',
            'domain' => $this->config['domain'] ?? null,
            'secure' => (bool) ($this->config['secure'] ?? false),
            'httponly' => (bool) ($this->config['http_only'] ?? true),
            'samesite' => $this->config['samesite'] ?? 'Lax',
        ]) !== false;
    }

    /**
     * 开启 session（Cookie 驱动无需锁）
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function open(string $id): bool
    {
        return true;
    }

    /**
     * 关闭 session
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function close(string $id): bool
    {
        return $this->save($id);
    }

    /**
     * 销毁 session
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function destroy(string $id): bool
    {
        return $this->clear($id);
    }

    /**
     * 垃圾回收（Cookie 由浏览器自动过期清理）
     *
     * @param int $maxLifetime 最大生命周期
     */
    #[\Override]
    public function gc(int $maxLifetime): int
    {
        return 0;
    }

    /**
     * 迁移数据到新 ID（Cookie 为单实例，重写 id 字段即可）
     *
     * @param string $fromId 原 Session ID
     * @param string $toId   新 Session ID
     * @param bool   $delete 是否删除原数据
     */
    #[\Override]
    public function migrate(string $fromId, string $toId, bool $delete = true): bool
    {
        // Cookie 以单条形式承载整个会话，仅需以新 id 重写
        return $this->save($toId);
    }

    /**
     * 获取 session 所有数据（已解包并剔除过期项）
     *
     * @param string $id Session ID
     * @return array<string, mixed>
     */
    #[\Override]
    public function all(string $id): array
    {
        $this->load();
        $result = [];

        foreach ($this->data as $key => $value) {
            if ($this->isExpired($value)) {
                continue;
            }

            $result[$key] = $this->unwrap($value);
        }

        return $result;
    }

    /**
     * 获取分布式锁（Cookie 驱动不支持，返回成功）
     *
     * @param string   $id      Session ID
     * @param int|null $timeout 超时时间
     */
    #[\Override]
    public function acquireLock(string $id, ?int $timeout = null): bool
    {
        return true;
    }

    /**
     * 释放分布式锁（Cookie 驱动不支持，返回成功）
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function releaseLock(string $id): bool
    {
        return true;
    }
}
