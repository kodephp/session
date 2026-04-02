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
     * @param string $id     Session ID
     * @param string $name   键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get(string $id, string $name, mixed $default = null): mixed;

    /**
     * 设置 session 值
     *
     * @param string $id        Session ID
     * @param string $name       键名
     * @param mixed  $value      值
     * @param int    $lifetime   生命周期（秒），0表示永久
     * @return bool
     */
    public function set(string $id, string $name, mixed $value, int $lifetime = 0): bool;

    /**
     * 删除 session 值
     *
     * @param string $id   Session ID
     * @param string $name 键名
     * @return bool
     */
    public function delete(string $id, string $name): bool;

    /**
     * 检查 session 是否存在
     *
     * @param string $id   Session ID
     * @param string $name 键名
     * @return bool
     */
    public function has(string $id, string $name): bool;

    /**
     * 清空指定 session 的所有数据
     *
     * @param string $id Session ID
     * @return bool
     */
    public function clear(string $id): bool;

    /**
     * 获取并删除值（原子操作）
     *
     * @param string $id     Session ID
     * @param string $name   键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function pull(string $id, string $name, mixed $default = null): mixed;

    /**
     * 不存在时执行回调并存储结果
     *
     * @param string   $id        Session ID
     * @param string   $name      键名
     * @param callable $callback   回调函数
     * @param int      $lifetime  生命周期
     * @return mixed
     */
    public function remember(string $id, string $name, callable $callback, int $lifetime = 0): mixed;

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
     * @return int 清理的过期 session 数量
     */
    public function gc(int $maxLifetime): int;

    /**
     * 获取 session 所有数据
     *
     * @param string $id Session ID
     * @return array
     */
    public function all(string $id): array;

    /**
     * 生成 session ID
     *
     * @return string
     */
    public function generateId(): string;
}
