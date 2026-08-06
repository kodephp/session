<?php

declare(strict_types=1);

namespace Kode\Session\Contract;

/**
 * Session 驱动接口
 * 所有驱动必须实现此接口
 *
 * @author kode
 */
interface Driver
{
    /**
     * 获取 session 值
     *
     * @param string $id      Session ID
     * @param string $name    键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get(string $id, string $name, mixed $default = null): mixed;

    /**
     * 设置 session 值
     *
     * @param string $id       Session ID
     * @param string $name     键名
     * @param mixed  $value    值
     * @param int    $lifetime 生命周期（秒），0 表示跟随驱动默认策略
     * @return bool
     */
    public function set(string $id, string $name, mixed $value, int $lifetime = 0): bool;

    /**
     * 批量写入（一次落盘 / 一次网络往返）
     *
     * @param string               $id       Session ID
     * @param array<string, mixed> $values   键值对
     * @param int                  $lifetime 生命周期（秒）
     * @return bool
     */
    public function setMultiple(string $id, array $values, int $lifetime = 0): bool;

    /**
     * 删除 session 值
     *
     * @param string $id   Session ID
     * @param string $name 键名
     * @return bool
     */
    public function delete(string $id, string $name): bool;

    /**
     * 检查键是否存在（存在但值为 null 也算存在）
     *
     * @param string $id   Session ID
     * @param string $name 键名
     * @return bool
     */
    public function has(string $id, string $name): bool;

    /**
     * 检查该 session 在存储中是否存在
     *
     * @param string $id Session ID
     * @return bool
     */
    public function exists(string $id): bool;

    /**
     * 清空指定 session 的所有数据
     *
     * @param string $id Session ID
     * @return bool
     */
    public function clear(string $id): bool;

    /**
     * 获取并删除值
     *
     * @param string $id      Session ID
     * @param string $name    键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function pull(string $id, string $name, mixed $default = null): mixed;

    /**
     * 不存在时执行回调并存储结果
     *
     * @param string   $id       Session ID
     * @param string   $name     键名
     * @param callable $callback 回调函数
     * @param int      $lifetime 生命周期
     * @return mixed
     */
    public function remember(string $id, string $name, callable $callback, int $lifetime = 0): mixed;

    /**
     * 将 session 数据整体迁移到新 ID（用于 regenerate 防会话固定）
     *
     * @param string $fromId 原 Session ID
     * @param string $toId   新 Session ID
     * @param bool   $delete 是否删除原数据
     * @return bool
     */
    public function migrate(string $fromId, string $toId, bool $delete = true): bool;

    /**
     * 开启 session（获取锁）
     *
     * @param string $id Session ID
     * @return bool
     */
    public function open(string $id): bool;

    /**
     * 关闭 session（释放锁）
     *
     * @param string $id Session ID
     * @return bool
     */
    public function close(string $id): bool;

    /**
     * 销毁 session
     *
     * @param string $id Session ID
     * @return bool
     */
    public function destroy(string $id): bool;

    /**
     * 垃圾回收
     *
     * @param int $maxLifetime 最大生命周期（秒）
     * @return int 清理的过期条目数量
     */
    public function gc(int $maxLifetime): int;

    /**
     * 获取 session 所有数据（已解包为「键 => 原始值」，并剔除过期项）
     *
     * @param string $id Session ID
     * @return array<string, mixed>
     */
    public function all(string $id): array;

    /**
     * 生成 session ID
     *
     * @return string
     */
    public function generateId(): string;

    /**
     * 获取分布式锁
     *
     * @param string   $id      Session ID
     * @param int|null $timeout 等待超时时间（秒），null 表示使用驱动默认值
     * @return bool
     */
    public function acquireLock(string $id, ?int $timeout = null): bool;

    /**
     * 释放分布式锁
     *
     * @param string $id Session ID
     * @return bool
     */
    public function releaseLock(string $id): bool;
}
