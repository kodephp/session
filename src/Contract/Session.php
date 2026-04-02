<?php

declare(strict_types=1);

namespace Kode\Session\Contract;

use ArrayAccess;
use Countable;
use IteratorAggregate;

/**
 * Session 会话接口
 *
 * @author kode
 */
interface Session extends ArrayAccess, Countable, IteratorAggregate
{
    /**
     * 获取 session ID
     *
     * @return string
     */
    public function getId(): string;

    /**
     * 获取 session 名称
     *
     * @return string
     */
    public function getName(): string;

    /**
     * 检查 session 是否已启动
     *
     * @return bool
     */
    public function isStarted(): bool;

    /**
     * 启动 session
     *
     * @return bool
     */
    public function start(): bool;

    /**
     * 关闭 session
     *
     * @return bool
     */
    public function close(): bool;

    /**
     * 销毁 session
     *
     * @return bool
     */
    public function destroy(): bool;

    /**
     * 重新生成 session ID
     *
     * @param bool $delete 是否删除旧 session 数据
     * @return bool
     */
    public function regenerate(bool $delete = false): bool;

    /**
     * 保存 session 数据并关闭
     *
     * @return void
     */
    public function save(): void;

    /**
     * 获取所有数据
     *
     * @return array
     */
    public function all(): array;

    /**
     * 获取数据
     *
     * @param string $name    键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed;

    /**
     * 设置数据
     *
     * @param string $name  键名
     * @param mixed  $value 值
     * @return void
     */
    public function set(string $name, mixed $value): void;

    /**
     * 删除数据
     *
     * @param string $name 键名
     * @return bool
     */
    public function delete(string $name): bool;

    /**
     * 检查数据是否存在
     *
     * @param string $name 键名
     * @return bool
     */
    public function has(string $name): bool;

    /**
     * 清空所有数据
     *
     * @return bool
     */
    public function clear(): bool;

    /**
     * 获取并删除数据
     *
     * @param string $name    键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function pull(string $name, mixed $default = null): mixed;

    /**
     * 不存在时执行回调并存储结果
     *
     * @param string   $name      键名
     * @param callable $callback  回调函数
     * @param int      $lifetime 生命周期
     * @return mixed
     */
    public function remember(string $name, callable $callback, int $lifetime = 0): mixed;

    /**
     * 闪存数据（下一次请求后自动删除）
     *
     * @param string $name  键名
     * @param mixed  $value 值（为 null 时表示获取）
     * @return mixed
     */
    public function flash(string $name, mixed $value = null): mixed;

    /**
     * 保留闪存数据（用于重定向场景）
     *
     * @param array $keys 要保留的键列表
     * @return void
     */
    public function retainFlash(array $keys = []): void;

    /**
     * 清空闪存数据
     *
     * @return void
     */
    public function flushFlash(): void;

    /**
     * 记录之前请求的闪存数据
     *
     * @return void
     */
    public function ageFlash(): void;

    /**
     * 获取 CSRF token
     *
     * @param string|null $token 可选的新 token
     * @return string
     */
    public function token(string $token = null): string;

    /**
     * 验证 CSRF token
     *
     * @param string $token 待验证的 token
     * @return bool
     */
    public function verifyCsrfToken(string $token): bool;

    /**
     * 设置错误信息
     *
     * @param string $key     键名
     * @param string $message 错误信息
     * @return self
     */
    public function setError(string $key, string $message): self;

    /**
     * 获取错误信息
     *
     * @param string $key 键名
     * @return string|null
     */
    public function getError(string $key): ?string;

    /**
     * 获取所有错误信息
     *
     * @return array
     */
    public function getErrors(): array;

    /**
     * 检查是否有错误
     *
     * @param string|null $key 键名（为空时检查整体）
     * @return bool
     */
    public function hasError(string $key = null): bool;

    /**
     * 设置成功信息
     *
     * @param string $key     键名
     * @param string $message 成功信息
     * @return self
     */
    public function setSuccess(string $key, string $message): self;

    /**
     * 获取成功信息
     *
     * @param string $key 键名
     * @return string|null
     */
    public function getSuccess(string $key): ?string;

    /**
     * 获取所有成功信息
     *
     * @return array
     */
    public function getSuccesses(): array;

    /**
     * 检查是否有成功信息
     *
     * @param string|null $key 键名（为空时检查整体）
     * @return bool
     */
    public function hasSuccess(string $key = null): bool;

    /**
     * 获取原始数据（不包含系统键）
     *
     * @return array
     */
    public function raw(): array;
}
