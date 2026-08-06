<?php

declare(strict_types=1);

namespace Kode\Session\Driver;

/**
 * 数组（内存）驱动 - 会话数据仅存在于当前进程内存
 * 适合单元测试、CLI 临时会话、以及单请求内的 ephemeral 场景
 *
 * 注意：进程结束后数据即丢失，不可用于跨请求持久化。
 *
 * @author kode
 */
class ArrayDriver extends AbstractDriver
{
    /**
     * 内存存储
     *
     * @var array<string, array<string, array{data: mixed, expire: int}>>
     */
    protected array $store = [];

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
        $id = $this->validateId($id);
        $entry = $this->store[$id][$name] ?? null;

        if ($entry === null) {
            return $default;
        }

        if ($this->isExpired($entry)) {
            $this->delete($id, $name);

            return $default;
        }

        return $this->unwrap($entry, $default);
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
        $id = $this->validateId($id);
        $this->store[$id][$name] = $this->wrap($value, $lifetime);

        return true;
    }

    /**
     * 批量写入
     *
     * @param string               $id       Session ID
     * @param array<string, mixed> $values   键值对
     * @param int                  $lifetime 生命周期
     */
    #[\Override]
    public function setMultiple(string $id, array $values, int $lifetime = 0): bool
    {
        $id = $this->validateId($id);

        foreach ($values as $name => $value) {
            $this->store[$id][(string) $name] = $this->wrap($value, $lifetime);
        }

        return true;
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
        $id = $this->validateId($id);
        unset($this->store[$id][$name]);

        return true;
    }

    /**
     * 检查键是否存在
     *
     * @param string $id   Session ID
     * @param string $name 键名
     */
    #[\Override]
    public function has(string $id, string $name): bool
    {
        $id = $this->validateId($id);
        $entry = $this->store[$id][$name] ?? null;

        return $entry !== null && !$this->isExpired($entry);
    }

    /**
     * 会话是否存在
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function exists(string $id): bool
    {
        $id = $this->validateId($id);

        return isset($this->store[$id]) && $this->store[$id] !== [];
    }

    /**
     * 清空指定 session 的所有数据
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function clear(string $id): bool
    {
        $id = $this->validateId($id);
        unset($this->store[$id]);

        return true;
    }

    /**
     * 开启 session
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
        return true;
    }

    /**
     * 销毁 session
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function destroy(string $id): bool
    {
        $id = $this->validateId($id);
        unset($this->store[$id]);

        return true;
    }

    /**
     * 垃圾回收
     *
     * @param int $maxLifetime 最大生命周期
     */
    #[\Override]
    public function gc(int $maxLifetime): int
    {
        $count = 0;

        foreach ($this->store as $id => $entries) {
            foreach ($entries as $name => $entry) {
                if ($this->isExpired($entry)) {
                    unset($this->store[$id][$name]);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * 迁移数据到新 ID
     *
     * @param string $fromId 原 Session ID
     * @param string $toId   新 Session ID
     * @param bool   $delete 是否删除原数据
     */
    #[\Override]
    public function migrate(string $fromId, string $toId, bool $delete = true): bool
    {
        if ($fromId === $toId) {
            return true;
        }

        $fromId = $this->validateId($fromId);
        $toId = $this->validateId($toId);

        if (isset($this->store[$fromId])) {
            $this->store[$toId] = $this->store[$fromId];
        }

        if ($delete) {
            unset($this->store[$fromId]);
        }

        return true;
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
        $id = $this->validateId($id);
        $result = [];

        foreach ($this->store[$id] ?? [] as $name => $entry) {
            if ($this->isExpired($entry)) {
                continue;
            }

            $result[$name] = $this->unwrap($entry);
        }

        return $result;
    }
}
