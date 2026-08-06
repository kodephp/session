<?php

declare(strict_types=1);

namespace Kode\Session\Support;

/**
 * Fiber 安全存储 - 在 Fiber 中安全传递 session
 * 解决 PHP Fiber 中的数据隔离问题
 *
 * 使用 WeakMap 以 Fiber 对象为键，Fiber 被回收后对应存储自动释放，
 * 避免静态数组长期持有已结束 Fiber 的引用造成内存泄漏。
 *
 * @author kode
 */
final class FiberSessionStorage
{
    /**
     * 以 Fiber 为键的弱引用存储
     *
     * @var WeakMap<\Fiber<mixed>, array<string, mixed>>|null
     */
    private static ?WeakMap $storages = null;

    /**
     * 获取（惰性初始化）WeakMap
     *
     * @return WeakMap<\Fiber<mixed>, array<string, mixed>>
     */
    private static function storage(): WeakMap
    {
        if (self::$storages === null) {
            self::$storages = new WeakMap();
        }

        return self::$storages;
    }

    /**
     * 在 fiber 中存储 session
     *
     * @param string $key   键名
     * @param mixed  $value 值
     */
    public static function set(string $key, mixed $value): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return;
        }

        $map = self::storage();
        $store = $map->offsetExists($fiber) ? $map->offsetGet($fiber) : [];
        $store[$key] = $value;
        $map->offsetSet($fiber, $store);
    }

    /**
     * 从 fiber 中获取 session
     *
     * @param string $key     键名
     * @param mixed  $default 默认值
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return $default;
        }

        $map = self::storage();

        if (!$map->offsetExists($fiber)) {
            return $default;
        }

        return $map->offsetGet($fiber)[$key] ?? $default;
    }

    /**
     * 检查 fiber 中是否存在
     *
     * @param string $key 键名
     */
    public static function has(string $key): bool
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return false;
        }

        $map = self::storage();

        if (!$map->offsetExists($fiber)) {
            return false;
        }

        return array_key_exists($key, $map->offsetGet($fiber));
    }

    /**
     * 从 fiber 中删除 session
     *
     * @param string $key 键名
     */
    public static function delete(string $key): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return;
        }

        $map = self::storage();

        if (!$map->offsetExists($fiber)) {
            return;
        }

        $store = $map->offsetGet($fiber);
        unset($store[$key]);
        $map->offsetSet($fiber, $store);
    }

    /**
     * 清空 fiber 的所有 session
     */
    public static function clear(): void
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return;
        }

        self::storage()->offsetUnset($fiber);
    }

    /**
     * 获取当前 fiber 的所有数据
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return [];
        }

        $map = self::storage();

        return $map->offsetExists($fiber) ? $map->offsetGet($fiber) : [];
    }

    /**
     * 清理已结束的 fiber 存储（WeakMap 已自动回收，此方法保持兼容）
     */
    public static function cleanup(): void
    {
        // WeakMap 会在 Fiber 被 GC 后自动移除对应条目，无需手动清理
    }
}
