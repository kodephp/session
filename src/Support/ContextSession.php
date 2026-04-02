<?php

declare(strict_types=1);

namespace Kode\Session\Support;

use Kode\Session\Session;

/**
 * Context 会话隔离 - 实现请求内会话隔离
 * 配合 kode/context 做请求内会话隔离
 *
 * 使用 ThreadLocal 模式确保协程/多进程环境下的会话隔离
 *
 * @author kode
 */
class ContextSession
{
    /**
     * 当前请求的 session 存储
     */
    protected static ?Session $current = null;

    /**
     * Session 属性存储
     */
    protected static array $attributes = [];

    /**
     * 设置当前 session
     *
     * @param Session $session session 实例
     * @return void
     */
    public static function setSession(Session $session): void
    {
        self::$current = $session;
    }

    /**
     * 获取当前 session
     *
     * @return Session|null
     */
    public static function getSession(): ?Session
    {
        return self::$current;
    }

    /**
     * 检查是否有 session
     *
     * @return bool
     */
    public static function hasSession(): bool
    {
        return self::$current !== null;
    }

    /**
     * 清除当前 session
     *
     * @return void
     */
    public static function clearSession(): void
    {
        self::$current = null;
        self::$attributes = [];
    }

    /**
     * 设置属性
     *
     * @param string $key   键名
     * @param mixed  $value 值
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        self::$attributes[$key] = $value;
    }

    /**
     * 获取属性
     *
     * @param string $key     键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$attributes[$key] ?? $default;
    }

    /**
     * 检查属性是否存在
     *
     * @param string $key 键名
     * @return bool
     */
    public static function has(string $key): bool
    {
        return isset(self::$attributes[$key]);
    }

    /**
     * 删除属性
     *
     * @param string $key 键名
     * @return void
     */
    public static function delete(string $key): void
    {
        unset(self::$attributes[$key]);
    }

    /**
     * 清空所有属性
     *
     * @return void
     */
    public static function clearAttributes(): void
    {
        self::$attributes = [];
    }

    /**
     * 获取所有属性
     *
     * @return array
     */
    public static function all(): array
    {
        return self::$attributes;
    }
}
