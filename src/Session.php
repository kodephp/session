<?php

declare(strict_types=1);

namespace Kode\Session;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Kode\Session\Contract\Driver;
use Kode\Session\Contract\Session as SessionContract;
use Traversable;

/**
 * Session 类 - 用户会话管理
 * 提供完整的 session 功能，包括闪存、错误/成功信息、CSRF 保护等
 *
 * @author kode
 */
class Session implements SessionContract, ArrayAccess, Countable, IteratorAggregate
{
    /**
     * Session ID
     */
    protected string $id;

    /**
     * Session 名称
     */
    protected string $name;

    /**
     * 是否已启动
     */
    protected bool $started = false;

    /**
     * 驱动实例
     */
    protected Driver $driver;

    /**
     * Session 数据
     */
    protected array $data = [];

    /**
     * 闪存数据（上一次请求的）
     */
    protected array $oldFlash = [];

    /**
     * 闪存数据（当前请求新增的）
     */
    protected array $newFlash = [];

    /**
     * 错误信息
     */
    protected array $errors = [];

    /**
     * 成功信息
     */
    protected array $successes = [];

    /**
     * CSRF token
     */
    protected ?string $csrfToken = null;

    /**
     * 系统键（不可遍历的键）
     */
    protected const SYSTEM_KEYS = [
        '_flash_old',
        '_flash_new',
        '_errors',
        '_successes',
        '_csrf_token',
    ];

    /**
     * 构造函数
     *
     * @param string $id     Session ID
     * @param string $name   Session 名称
     * @param Driver $driver 驱动实例
     */
    public function __construct(string $id, string $name, Driver $driver)
    {
        $this->id = $id;
        $this->name = $name;
        $this->driver = $driver;
    }

    /**
     * 获取 session ID
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * 获取 session 名称
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 检查 session 是否已启动
     *
     * @return bool
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * 启动 session
     *
     * @return bool
     */
    public function start(): bool
    {
        if ($this->started) {
            return true;
        }

        if ($this->driver->open($this->id)) {
            $this->data = $this->driver->all($this->id);
            $this->ageFlash();
            $this->started = true;
            return true;
        }

        return false;
    }

    /**
     * 关闭 session
     *
     * @return bool
     */
    public function close(): bool
    {
        if (!$this->started) {
            return true;
        }

        $this->ageFlash();
        $this->driver->close($this->id);
        $this->started = false;

        return true;
    }

    /**
     * 销毁 session
     *
     * @return bool
     */
    public function destroy(): bool
    {
        $this->data = [];
        $this->oldFlash = [];
        $this->newFlash = [];
        $this->errors = [];
        $this->successes = [];
        $this->csrfToken = null;

        return $this->driver->destroy($this->id);
    }

    /**
     * 重新生成 session ID
     *
     * @param bool $delete 是否删除旧 session 数据
     * @return bool
     */
    public function regenerate(bool $delete = false): bool
    {
        $oldId = $this->id;

        do {
            $this->id = $this->driver->generateId();
        } while ($this->has($this->id));

        if ($delete) {
            $this->driver->destroy($oldId);
        }

        return true;
    }

    /**
     * 保存 session 数据并关闭
     *
     * @return void
     */
    public function save(): void
    {
        if (!$this->started) {
            return;
        }

        foreach ($this->data as $key => $value) {
            $this->driver->set($this->id, $key, $value);
        }

        $this->driver->close($this->id);
    }

    /**
     * 获取所有数据
     *
     * @return array
     */
    public function all(): array
    {
        return $this->raw();
    }

    /**
     * 获取数据
     *
     * @param string $name    键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed
    {
        if (isset($this->data[$name])) {
            return $this->data[$name];
        }

        if (in_array($name, self::SYSTEM_KEYS, true)) {
            return $default;
        }

        $value = $this->driver->get($this->id, $name, $default);

        if ($value !== $default) {
            $this->data[$name] = $value;
        }

        return $value;
    }

    /**
     * 设置数据
     *
     * @param string $name  键名
     * @param mixed  $value 值
     * @return void
     */
    public function set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
        $this->driver->set($this->id, $name, $value);
    }

    /**
     * 删除数据
     *
     * @param string $name 键名
     * @return bool
     */
    public function delete(string $name): bool
    {
        if (!isset($this->data[$name])) {
            return true;
        }

        unset($this->data[$name]);
        return $this->driver->delete($this->id, $name);
    }

    /**
     * 检查数据是否存在
     *
     * @param string $name 键名
     * @return bool
     */
    public function has(string $name): bool
    {
        if (isset($this->data[$name])) {
            return true;
        }

        if (in_array($name, self::SYSTEM_KEYS, true)) {
            return false;
        }

        if (isset($this->newFlash[$name]) || isset($this->oldFlash[$name])) {
            return true;
        }

        return $this->driver->has($this->id, $name);
    }

    /**
     * 清空所有数据
     *
     * @return bool
     */
    public function clear(): bool
    {
        $this->data = [];
        return $this->driver->clear($this->id);
    }

    /**
     * 获取并删除数据
     *
     * @param string $name    键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function pull(string $name, mixed $default = null): mixed
    {
        $value = $this->get($name, $default);
        $this->delete($name);
        return $value;
    }

    /**
     * 不存在时执行回调并存储结果
     *
     * @param string   $name      键名
     * @param callable $callback  回调函数
     * @param int      $lifetime 生命周期
     * @return mixed
     */
    public function remember(string $name, callable $callback, int $lifetime = 0): mixed
    {
        if ($this->has($name)) {
            return $this->get($name);
        }

        $value = $callback();
        $this->set($name, $value);
        return $value;
    }

    /**
     * 闪存数据（下一次请求后自动删除）
     *
     * @param string $name  键名
     * @param mixed  $value 值（为 null 时表示获取）
     * @return mixed
     */
    public function flash(string $name, mixed $value = null): mixed
    {
        if ($value === null) {
            return $this->getFlash($name);
        }

        $this->newFlash[$name] = $value;
        $this->data[$name] = $value;
        return $value;
    }

    /**
     * 保留闪存数据（用于重定向场景）
     *
     * @param array $keys 要保留的键列表
     * @return void
     */
    public function retainFlash(array $keys = []): void
    {
        if (empty($keys)) {
            $this->oldFlash = array_merge($this->oldFlash, $this->newFlash);
        } else {
            foreach ($keys as $key) {
                if (isset($this->newFlash[$key])) {
                    $this->oldFlash[$key] = $this->newFlash[$key];
                }
            }
        }

        $this->newFlash = [];
    }

    /**
     * 清空闪存数据
     *
     * @return void
     */
    public function flushFlash(): void
    {
        foreach ($this->oldFlash as $key => $value) {
            unset($this->data[$key]);
        }

        foreach ($this->newFlash as $key => $value) {
            unset($this->data[$key]);
        }

        $this->oldFlash = [];
        $this->newFlash = [];
    }

    /**
     * 记录之前请求的闪存数据
     *
     * @return void
     */
    public function ageFlash(): void
    {
        foreach ($this->oldFlash as $key => $value) {
            unset($this->data[$key]);
        }

        $this->oldFlash = $this->newFlash;
        $this->newFlash = [];
    }

    /**
     * 获取 CSRF token
     *
     * @param string|null $token 可选的新 token
     * @return string
     */
    public function token(string $token = null): string
    {
        if ($token !== null) {
            $this->csrfToken = $token;
            $this->set('_csrf_token', $token);
            return $token;
        }

        if ($this->csrfToken === null) {
            $this->csrfToken = $this->get('_csrf_token');

            if ($this->csrfToken === null) {
                $this->csrfToken = bin2hex(random_bytes(32));
                $this->set('_csrf_token', $this->csrfToken);
            }
        }

        return $this->csrfToken;
    }

    /**
     * 验证 CSRF token
     *
     * @param string $token 待验证的 token
     * @return bool
     */
    public function verifyCsrfToken(string $token): bool
    {
        return hash_equals($this->token(), $token);
    }

    /**
     * 设置错误信息
     *
     * @param string $key     键名
     * @param string $message 错误信息
     * @return self
     */
    public function setError(string $key, string $message): self
    {
        $this->errors[$key] = $message;
        return $this;
    }

    /**
     * 获取错误信息
     *
     * @param string $key 键名
     * @return string|null
     */
    public function getError(string $key): ?string
    {
        return $this->errors[$key] ?? null;
    }

    /**
     * 获取所有错误信息
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 检查是否有错误
     *
     * @param string|null $key 键名（为空时检查整体）
     * @return bool
     */
    public function hasError(string $key = null): bool
    {
        if ($key === null) {
            return !empty($this->errors);
        }

        return isset($this->errors[$key]);
    }

    /**
     * 设置成功信息
     *
     * @param string $key     键名
     * @param string $message 成功信息
     * @return self
     */
    public function setSuccess(string $key, string $message): self
    {
        $this->successes[$key] = $message;
        return $this;
    }

    /**
     * 获取成功信息
     *
     * @param string $key 键名
     * @return string|null
     */
    public function getSuccess(string $key): ?string
    {
        return $this->successes[$key] ?? null;
    }

    /**
     * 获取所有成功信息
     *
     * @return array
     */
    public function getSuccesses(): array
    {
        return $this->successes;
    }

    /**
     * 检查是否有成功信息
     *
     * @param string|null $key 键名（为空时检查整体）
     * @return bool
     */
    public function hasSuccess(string $key = null): bool
    {
        if ($key === null) {
            return !empty($this->successes);
        }

        return isset($this->successes[$key]);
    }

    /**
     * 获取原始数据（不包含系统键）
     *
     * @return array
     */
    public function raw(): array
    {
        $result = [];

        foreach ($this->data as $key => $value) {
            if (!in_array($key, self::SYSTEM_KEYS, true)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * 获取闪存数据
     *
     * @param string $name 键名
     * @return mixed
     */
    protected function getFlash(string $name): mixed
    {
        if (isset($this->newFlash[$name])) {
            return $this->newFlash[$name];
        }

        if (isset($this->oldFlash[$name])) {
            return $this->oldFlash[$name];
        }

        return null;
    }

    /**
     * 检查偏移量是否存在
     *
     * @param mixed $offset 偏移量
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    /**
     * 获取偏移量的值
     *
     * @param mixed $offset 偏移量
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * 设置偏移量的值
     *
     * @param mixed $offset 偏移量
     * @param mixed $value  值
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }

    /**
     * 删除偏移量
     *
     * @param mixed $offset 偏移量
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->delete($offset);
    }

    /**
     * 计算数据数量
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->raw());
    }

    /**
     * 获取迭代器
     *
     * @return Traversable
     */
    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->raw());
    }
}
