<?php

declare(strict_types=1);

namespace Kode\Session\Driver;

/**
 * Cookie 驱动 - 基于 Cookie 存储 session
 * 数据存储在客户端 Cookie 中，适合轻量级场景
 *
 * 注意：Cookie 有大小限制（通常 4KB），只适合存储少量数据
 *
 * @author kode
 */
class CookieDriver extends AbstractDriver
{
    /**
     * Cookie 数据
     */
    protected array $data = [];

    /**
     * 是否已加载
     */
    protected bool $loaded = false;

    /**
     * 构造函数
     *
     * @param array $config 配置数组
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->load();
    }

    /**
     * 从 Cookie 加载数据
     *
     * @return void
     */
    protected function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->data = [];

        $cookieName = $this->config['name'] ?? 'kode_session';
        $cookieData = $_COOKIE[$cookieName] ?? null;

        if ($cookieData === null) {
            return;
        }

        $decoded = base64_decode($cookieData, true);

        if ($decoded === false) {
            return;
        }

        $data = json_decode($decoded, true);

        if (!is_array($data)) {
            return;
        }

        $this->data = $data;
    }

    /**
     * 保存数据到 Cookie
     *
     * @param string $id Session ID
     * @return bool
     */
    protected function save(string $id): bool
    {
        $cookieName = $this->config['name'] ?? 'kode_session';
        $lifetime = $this->config['lifetime'] ?? 0;
        $path = $this->config['path'] ?? '/';
        $domain = $this->config['domain'] ?? null;
        $secure = $this->config['secure'] ?? false;
        $httpOnly = $this->config['http_only'] ?? true;

        $data = [
            'id' => $id,
            'data' => $this->data,
            'expire' => $lifetime > 0 ? time() + $lifetime : 0,
        ];

        $encoded = base64_encode(json_encode($data, JSON_THROW_ON_ERROR));

        $expire = $lifetime > 0 ? time() + $lifetime : 0;

        return setcookie($cookieName, $encoded, [
            'expires' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $this->config['samesite'] ?? 'Lax',
        ]) !== false;
    }

    /**
     * 获取 session 值
     *
     * @param string $id     Session ID
     * @param string $name   键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get(string $id, string $name, mixed $default = null): mixed
    {
        $this->load();

        if (!isset($this->data[$name])) {
            return $default;
        }

        $value = $this->data[$name];

        if ($this->isExpired($value)) {
            $this->delete($id, $name);
            return $default;
        }

        return $value['data'] ?? $default;
    }

    /**
     * 设置 session 值
     *
     * @param string $id        Session ID
     * @param string $name       键名
     * @param mixed  $value      值
     * @param int    $lifetime   生命周期（秒），0表示永久
     * @return bool
     */
    public function set(string $id, string $name, mixed $value, int $lifetime = 0): bool
    {
        $this->load();

        $this->data[$name] = [
            'data' => $value,
            'expire' => $lifetime > 0 ? time() + $lifetime : 0,
        ];

        return $this->save($id);
    }

    /**
     * 删除 session 值
     *
     * @param string $id   Session ID
     * @param string $name 键名
     * @return bool
     */
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
     * 检查 session 是否存在
     *
     * @param string $id   Session ID
     * @param string $name 键名
     * @return bool
     */
    public function has(string $id, string $name): bool
    {
        return $this->get($id, $name, null) !== null;
    }

    /**
     * 清空指定 session 的所有数据
     *
     * @param string $id Session ID
     * @return bool
     */
    public function clear(string $id): bool
    {
        $this->data = [];

        $cookieName = $this->config['name'] ?? 'kode_session';
        return setcookie($cookieName, '', [
            'expires' => time() - 3600,
            'path' => '/',
        ]) !== false;
    }

    /**
     * 开启 session（Cookie 驱动不需要锁）
     *
     * @param string $id Session ID
     * @return bool
     */
    public function open(string $id): bool
    {
        return true;
    }

    /**
     * 关闭 session
     *
     * @param string $id Session ID
     * @return bool
     */
    public function close(string $id): bool
    {
        return $this->save($id);
    }

    /**
     * 销毁 session
     *
     * @param string $id Session ID
     * @return bool
     */
    public function destroy(string $id): bool
    {
        return $this->clear($id);
    }

    /**
     * 垃圾回收（Cookie 驱动由浏览器自动清理）
     *
     * @param int $maxLifetime 最大生命周期
     * @return int
     */
    public function gc(int $maxLifetime): int
    {
        return 0;
    }

    /**
     * 获取 session 所有数据
     *
     * @param string $id Session ID
     * @return array
     */
    public function all(string $id): array
    {
        $this->load();
        $result = [];

        foreach ($this->data as $key => $value) {
            if ($this->isExpired($value)) {
                continue;
            }
            $result[$key] = $value['data'] ?? null;
        }

        return $result;
    }

    /**
     * 检查数据是否过期
     *
     * @param array $value 数据值
     * @return bool
     */
    protected function isExpired(array $value): bool
    {
        if (!isset($value['expire']) || $value['expire'] === 0) {
            return false;
        }

        return time() > $value['expire'];
    }

    /**
     * 获取分布式锁（Cookie 驱动不支持）
     *
     * @param string $id Session ID
     * @param int|null $timeout 超时时间
     * @return bool
     */
    public function acquireLock(string $id, int $timeout = null): bool
    {
        return true;
    }

    /**
     * 释放分布式锁（Cookie 驱动不支持）
     *
     * @param string $id Session ID
     * @return bool
     */
    public function releaseLock(string $id): bool
    {
        return true;
    }
}
