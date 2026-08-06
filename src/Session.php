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
     * 常规会话数据（不含系统键与闪存键）
     */
    protected array $data = [];

    /**
     * 闪存数据（当前请求新增的，下一轮请求可用）
     *
     * @var array<string, mixed>
     */
    protected array $newFlash = [];

    /**
     * 闪存数据（上一轮请求保留下来的，本轮可用）
     *
     * @var array<string, mixed>
     */
    protected array $oldFlash = [];

    /**
     * 错误信息
     *
     * @var array<string, string>
     */
    protected array $errors = [];

    /**
     * 成功信息
     *
     * @var array<string, string>
     */
    protected array $successes = [];

    /**
     * CSRF token
     */
    protected ?string $csrfToken = null;

    /**
     * 已变更（待落盘）的键集合
     *
     * @var array<string, true>
     */
    protected array $dirty = [];

    /**
     * 本次请求内被删除（待落盘删除）的键集合
     *
     * @var array<string, true>
     */
    protected array $removed = [];

    /**
     * 闪存键：本轮新增
     */
    protected const FLASH_NEW = '_flash_new';

    /**
     * 闪存键：上一轮保留
     */
    protected const FLASH_OLD = '_flash_old';

    /**
     * 系统键（不可遍历、不可作为常规数据导出）
     */
    protected const SYSTEM_KEYS = [
        self::FLASH_NEW,
        self::FLASH_OLD,
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
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * 获取 session 名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 检查 session 是否已启动
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * 启动 session
     */
    public function start(): bool
    {
        if ($this->started) {
            return true;
        }

        if ($this->driver->open($this->id)) {
            $this->data = $this->driver->all($this->id);

            // 还原持久化的闪存列表
            $this->newFlash = $this->extractFlash(self::FLASH_NEW);
            $this->oldFlash = $this->extractFlash(self::FLASH_OLD);

            $this->ageFlash();
            $this->started = true;

            return true;
        }

        return false;
    }

    /**
     * 从数据数组中取出并移除指定闪存列表
     *
     * @return array<string, mixed>
     */
    protected function extractFlash(string $key): array
    {
        $value = $this->data[$key] ?? [];
        unset($this->data[$key]);

        return is_array($value) ? $value : [];
    }

    /**
     * 关闭 session（兜底落盘未保存的变更，再释放锁）
     */
    public function close(): bool
    {
        if (!$this->started) {
            return true;
        }

        $this->ageFlash();
        $this->flushData();
        $this->driver->close($this->id);
        $this->started = false;

        return true;
    }

    /**
     * 销毁 session
     */
    public function destroy(): bool
    {
        $this->dirty = [];
        $this->removed = [];
        $this->data = [];
        $this->oldFlash = [];
        $this->newFlash = [];
        $this->errors = [];
        $this->successes = [];
        $this->csrfToken = null;

        return $this->driver->destroy($this->id);
    }

    /**
     * 重新生成 session ID（迁移旧数据到新 ID，防会话固定）
     *
     * 先把缓存中未落盘的变更刷写到旧 ID，确保 migrate 不会丢失本请求的写入。
     *
     * @param bool $delete 是否删除旧 session 数据
     */
    public function regenerate(bool $delete = false): bool
    {
        $oldId = $this->id;
        $this->flushData();

        do {
            $this->id = $this->driver->generateId();
        } while ($this->id === $oldId || $this->driver->exists($this->id));

        return $this->driver->migrate($oldId, $this->id, $delete);
    }

    /**
     * 保存 session 数据并关闭
     *
     * 采用延迟写盘：set/delete 仅更新内存与脏标记，真正落盘在此一次性批量完成，
     * 避免每个 set 都触发一次驱动 I/O（文件重写 / Redis 往返）。
     */
    public function save(): void
    {
        if (!$this->started) {
            return;
        }

        $this->flushData();
        $this->driver->set($this->id, self::FLASH_NEW, $this->newFlash, 0);
        $this->driver->set($this->id, self::FLASH_OLD, $this->oldFlash, 0);

        $this->driver->close($this->id);
        $this->started = false;
    }

    /**
     * 获取所有数据（不含系统键与闪存键）
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
     */
    public function get(string $name, mixed $default = null): mixed
    {
        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }

        if (array_key_exists($name, $this->newFlash)) {
            return $this->newFlash[$name];
        }

        if (array_key_exists($name, $this->oldFlash)) {
            return $this->oldFlash[$name];
        }

        // 本次请求内已删除、尚未落盘的键，直接视为不存在
        if (isset($this->removed[$name])) {
            return $default;
        }

        if (in_array($name, self::SYSTEM_KEYS, true)) {
            return $default;
        }

        if ($this->driver->has($this->id, $name)) {
            $value = $this->driver->get($this->id, $name, $default);
            $this->data[$name] = $value;

            return $value;
        }

        return $default;
    }

    /**
     * 设置数据
     *
     * @param string $name  键名
     * @param mixed  $value 值
     */
    public function set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
        $this->dirty[$name] = true;
        unset($this->removed[$name]);
    }

    /**
     * 删除数据
     *
     * @param string $name 键名
     */
    public function delete(string $name): bool
    {
        unset($this->data[$name]);
        $this->removed[$name] = true;
        unset($this->dirty[$name]);

        return true;
    }

    /**
     * 检查数据是否存在（闪存键也算存在）
     *
     * @param string $name 键名
     */
    public function has(string $name): bool
    {
        if (array_key_exists($name, $this->data)) {
            return true;
        }

        if (array_key_exists($name, $this->newFlash) || array_key_exists($name, $this->oldFlash)) {
            return true;
        }

        if (isset($this->removed[$name])) {
            return false;
        }

        if (in_array($name, self::SYSTEM_KEYS, true)) {
            return false;
        }

        return $this->driver->has($this->id, $name);
    }

    /**
     * 清空所有数据（含闪存）
     */
    public function clear(): bool
    {
        foreach (array_keys($this->data) as $name) {
            $this->removed[$name] = true;
            unset($this->dirty[$name]);
        }

        $this->data = [];
        $this->newFlash = [];
        $this->oldFlash = [];

        return $this->driver->clear($this->id);
    }

    /**
     * 将内存中的脏变更与删除标记批量落盘
     *
     * 变更键通过 setMultiple 一次性写入；删除键逐条删除（驱动层可批量优化）。
     * 调用后清空脏标记，使后续 get/has 直接走内存或驱动最新状态。
     */
    protected function flushData(): void
    {
        if ($this->dirty !== []) {
            $updates = [];

            foreach ($this->dirty as $name => $_) {
                if (!isset($this->removed[$name])) {
                    $updates[$name] = $this->data[$name] ?? null;
                }
            }

            if ($updates !== []) {
                $this->driver->setMultiple($this->id, $updates, 0);
            }
        }

        if ($this->removed !== []) {
            foreach (array_keys($this->removed) as $name) {
                $this->driver->delete($this->id, $name);
            }
        }

        $this->dirty = [];
        $this->removed = [];
    }

    /**
     * 是否有未落盘的变更（脏数据）
     */
    public function isDirty(): bool
    {
        return $this->dirty !== [] || $this->removed !== [];
    }

    /**
     * isDirty 的别名
     */
    public function hasChanges(): bool
    {
        return $this->isDirty();
    }

    /**
     * 获取并删除数据
     *
     * @param string $name    键名
     * @param mixed  $default 默认值
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
     * 向数组键追加一个值
     *
     * @param string $name  键名
     * @param mixed  $value 值
     */
    public function push(string $name, mixed $value): void
    {
        $array = $this->get($name, []);

        if (!is_array($array)) {
            $array = [$array];
        }

        $array[] = $value;

        $this->set($name, $array);
    }

    /**
     * 数值自增
     *
     * @param string $name 键名
     * @param int    $step 步长
     */
    public function increment(string $name, int $step = 1): int
    {
        $value = (int) $this->get($name, 0) + $step;
        $this->set($name, $value);

        return $value;
    }

    /**
     * 数值自减
     *
     * @param string $name 键名
     * @param int    $step 步长
     */
    public function decrement(string $name, int $step = 1): int
    {
        $value = (int) $this->get($name, 0) - $step;
        $this->set($name, $value);

        return $value;
    }

    /**
     * 仅返回指定键的数据
     *
     * @param array $keys 键列表
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    /**
     * 返回除指定键之外的数据
     *
     * @param array $keys 要排除的键列表
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->raw(), array_flip($keys));
    }

    /**
     * 整体替换为新数据
     *
     * @param array<string, mixed> $attributes 新数据
     */
    public function replace(array $attributes): void
    {
        $this->clear();

        foreach ($attributes as $key => $value) {
            $this->set((string) $key, $value);
        }
    }

    /**
     * 删除指定键（支持批量）
     *
     * @param string|array $key 键名或键名数组
     */
    public function forget(string|array $key): void
    {
        $keys = is_array($key) ? $key : [$key];

        foreach ($keys as $k) {
            $this->delete((string) $k);
        }
    }

    /**
     * 清空所有数据（clear 的别名）
     */
    public function flush(): void
    {
        $this->clear();
    }

    /**
     * 闪存数据（下一轮请求可用）
     * 省略 value 或仅传 name 时读取；显式传 null 视为存储 null 值。
     *
     * @param string $name  键名
     * @param mixed  $value 值
     */
    public function flash(string $name, mixed $value = null): mixed
    {
        if (func_num_args() < 2) {
            return $this->getFlash($name);
        }

        $this->newFlash[$name] = $value;

        return $value;
    }

    /**
     * 仅当前请求可用的闪存（下一轮请求开始即失效）
     *
     * @param string $name  键名
     * @param mixed  $value 值
     */
    public function now(string $name, mixed $value): mixed
    {
        $this->oldFlash[$name] = $value;

        return $value;
    }

    /**
     * 读取上一次请求的闪存数据
     *
     * @param string $name    键名
     * @param mixed  $default 默认值
     */
    public function old(string $name, mixed $default = null): mixed
    {
        return $this->oldFlash[$name] ?? $default;
    }

    /**
     * 保留闪存数据（使其多存活一轮）
     *
     * @param array $keys 要保留的键列表
     */
    public function keep(array $keys = []): void
    {
        if ($keys === []) {
            $this->newFlash = array_merge($this->newFlash, $this->oldFlash);

            return;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $this->oldFlash)) {
                $this->newFlash[$key] = $this->oldFlash[$key];
            }
        }
    }

    /**
     * 保留闪存数据（用于重定向场景）
     *
     * @param array $keys 要保留的键列表
     */
    public function retainFlash(array $keys = []): void
    {
        $this->keep($keys);
    }

    /**
     * 清空当前请求的闪存数据
     */
    public function flushFlash(): void
    {
        $this->oldFlash = [];
        $this->newFlash = [];
    }

    /**
     * 记录之前请求的闪存数据（每轮请求开始时调用）
     */
    public function ageFlash(): void
    {
        // 上一轮旧闪存已过期：清空
        $this->oldFlash = $this->newFlash;
        $this->newFlash = [];
    }

    /**
     * 获取 CSRF token
     *
     * @param string|null $token 可选的新 token
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
     */
    public function getError(string $key): ?string
    {
        return $this->errors[$key] ?? null;
    }

    /**
     * 获取所有错误信息
     *
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 检查是否有错误
     *
     * @param string|null $key 键名（为空时检查整体）
     */
    public function hasError(string $key = null): bool
    {
        if ($key === null) {
            return $this->errors !== [];
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
     */
    public function getSuccess(string $key): ?string
    {
        return $this->successes[$key] ?? null;
    }

    /**
     * 获取所有成功信息
     *
     * @return array<string, string>
     */
    public function getSuccesses(): array
    {
        return $this->successes;
    }

    /**
     * 检查是否有成功信息
     *
     * @param string|null $key 键名（为空时检查整体）
     */
    public function hasSuccess(string $key = null): bool
    {
        if ($key === null) {
            return $this->successes !== [];
        }

        return isset($this->successes[$key]);
    }

    /**
     * 获取原始数据（不包含系统键与闪存键）
     *
     * @return array<string, mixed>
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
     */
    protected function getFlash(string $name): mixed
    {
        if (array_key_exists($name, $this->newFlash)) {
            return $this->newFlash[$name];
        }

        if (array_key_exists($name, $this->oldFlash)) {
            return $this->oldFlash[$name];
        }

        return null;
    }

    /**
     * 检查偏移量是否存在
     *
     * @param mixed $offset 偏移量
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    /**
     * 获取偏移量的值
     *
     * @param mixed $offset 偏移量
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    /**
     * 设置偏移量的值
     *
     * @param mixed $offset 偏移量
     * @param mixed $value  值
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    /**
     * 删除偏移量
     *
     * @param mixed $offset 偏移量
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->delete((string) $offset);
    }

    /**
     * 计算数据数量
     */
    public function count(): int
    {
        return count($this->raw());
    }

    /**
     * 获取迭代器
     */
    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->raw());
    }
}
