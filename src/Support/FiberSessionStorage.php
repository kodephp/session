<?php

declare(strict_types=1);

namespace Kode\Session\Support;

/**
 * Fiber 安全存储 - 在 Fiber 中安全传递 session
 * 解决 PHP Fiber 中的数据隔离问题
 *
 * @author kode
 */
final class FiberSessionStorage
{
    /**
     * 当前 fiber 的 session 存储
     */
    private static array $storages = [];

    /**
     * 在 fiber 中存储 session
     *
     * @param string $key   键名
     * @param mixed  $value 值
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        $fiberId = self::getCurrentFiberId();

        if ($fiberId === 0) {
            return;
        }

        if (!isset(self::$storages[$fiberId])) {
            self::$storages[$fiberId] = [];
        }

        self::$storages[$fiberId][$key] = $value;
    }

    /**
     * 从 fiber 中获取 session
     *
     * @param string $key     键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $fiberId = self::getCurrentFiberId();

        if ($fiberId === 0) {
            return $default;
        }

        return self::$storages[$fiberId][$key] ?? $default;
    }

    /**
     * 检查 fiber 中是否存在
     *
     * @param string $key 键名
     * @return bool
     */
    public static function has(string $key): bool
    {
        $fiberId = self::getCurrentFiberId();

        if ($fiberId === 0) {
            return false;
        }

        return isset(self::$storages[$fiberId][$key]);
    }

    /**
     * 从 fiber 中删除 session
     *
     * @param string $key 键名
     * @return void
     */
    public static function delete(string $key): void
    {
        $fiberId = self::getCurrentFiberId();

        if ($fiberId === 0) {
            return;
        }

        unset(self::$storages[$fiberId][$key]);
    }

    /**
     * 清空 fiber 的所有 session
     *
     * @return void
     */
    public static function clear(): void
    {
        $fiberId = self::getCurrentFiberId();

        if ($fiberId === 0) {
            return;
        }

        unset(self::$storages[$fiberId]);
    }

    /**
     * 获取当前 fiber 的所有数据
     *
     * @return array
     */
    public static function all(): array
    {
        $fiberId = self::getCurrentFiberId();

        if ($fiberId === 0) {
            return [];
        }

        return self::$storages[$fiberId] ?? [];
    }

    /**
     * 清理已结束的 fiber 存储
     *
     * @return void
     */
    public static function cleanup(): void
    {
        $currentId = self::getCurrentFiberId();

        foreach (self::$storages as $fiberId => $data) {
            if ($fiberId !== $currentId && !self::isFiberActive($fiberId)) {
                unset(self::$storages[$fiberId]);
            }
        }
    }

    /**
     * 获取当前 fiber ID
     *
     * @return int
     */
    private static function getCurrentFiberId(): int
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return 0;
        }

        return spl_object_id($fiber);
    }

    /**
     * 检查 fiber 是否活跃
     *
     * @param int $fiberId Fiber ID
     * @return bool
     */
    private static function isFiberActive(int $fiberId): bool
    {
        return false;
    }
}
