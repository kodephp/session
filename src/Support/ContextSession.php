<?php

declare(strict_types=1);

namespace Kode\Session\Support;

use Kode\Context\Context;
use Kode\Session\Session;

/**
 * Context 会话隔离 - 实现请求内会话隔离
 * 配合 kode/context 做请求内会话隔离
 *
 * 使用 kode/context 的静态方法实现协程/进程安全的会话隔离
 *
 * @author kode
 */
class ContextSession
{
    /**
     * Session 上下文键名
     */
    public const SESSION_KEY = 'kode.session';

    /**
     * 属性上下文键名前缀
     */
    public const ATTR_PREFIX = 'kode.session.attr.';

    /**
     * 设置当前 session
     *
     * @param Session $session session 实例
     * @return void
     */
    public static function setSession(Session $session): void
    {
        Context::set(self::SESSION_KEY, $session);
    }

    /**
     * 获取当前 session
     *
     * @return Session|null
     */
    public static function getSession(): ?Session
    {
        return Context::get(self::SESSION_KEY);
    }

    /**
     * 检查是否有 session
     *
     * @return bool
     */
    public static function hasSession(): bool
    {
        return Context::has(self::SESSION_KEY);
    }

    /**
     * 清除当前 session
     *
     * @return void
     */
    public static function clearSession(): void
    {
        Context::delete(self::SESSION_KEY);
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
        Context::set(self::ATTR_PREFIX . $key, $value);
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
        return Context::get(self::ATTR_PREFIX . $key, $default);
    }

    /**
     * 检查属性是否存在
     *
     * @param string $key 键名
     * @return bool
     */
    public static function has(string $key): bool
    {
        return Context::has(self::ATTR_PREFIX . $key);
    }

    /**
     * 删除属性
     *
     * @param string $key 键名
     * @return void
     */
    public static function delete(string $key): void
    {
        Context::delete(self::ATTR_PREFIX . $key);
    }

    /**
     * 清空所有属性
     *
     * @return void
     */
    public static function clearAttributes(): void
    {
        $keys = Context::keys();
        $prefix = self::ATTR_PREFIX;

        foreach ($keys as $key) {
            if (str_starts_with($key, $prefix)) {
                Context::delete($key);
            }
        }
    }

    /**
     * 获取所有属性
     *
     * @return array
     */
    public static function all(): array
    {
        $result = [];
        $keys = Context::keys();
        $prefix = self::ATTR_PREFIX;

        foreach ($keys as $key) {
            if (str_starts_with($key, $prefix)) {
                $attrKey = substr($key, strlen($prefix));
                $result[$attrKey] = Context::get($key);
            }
        }

        return $result;
    }

    /**
     * 在隔离上下文中执行
     *
     * @param callable $callable 要执行的回调
     * @return mixed
     */
    public static function run(callable $callable): mixed
    {
        return Context::run(function () use ($callable) {
            $session = self::getSession();

            $result = $callable($session);

            return $result;
        });
    }

    /**
     * 在新进程中执行（自动传递上下文）
     *
     * @param callable $callable 要执行的回调
     * @param bool $inheritContext 是否继承上下文
     * @return mixed
     */
    public static function fork(callable $callable, bool $inheritContext = true): mixed
    {
        return Context::fork(function () use ($callable) {
            $session = self::getSession();

            return $callable($session);
        });
    }

    /**
     * 导出当前上下文数据
     *
     * @return array
     */
    public static function export(): array
    {
        return Context::export();
    }

    /**
     * 导入上下文数据
     *
     * @param array $data 数据
     * @param bool $merge 是否合并
     * @return void
     */
    public static function import(array $data, bool $merge = false): void
    {
        Context::import($data, $merge);
    }

    /**
     * 重置会话上下文
     *
     * @return void
     */
    public static function reset(): void
    {
        self::clearSession();
        self::clearAttributes();
        Context::reset();
    }

    /**
     * 获取追踪 ID
     *
     * @return string|null
     */
    public static function getTraceId(): ?string
    {
        $traceInfo = Context::getTraceInfo();
        return $traceInfo['trace_id'] ?? null;
    }

    /**
     * 获取追踪信息
     *
     * @return array
     */
    public static function getTraceInfo(): array
    {
        return Context::getTraceInfo();
    }
}
