<?php

declare(strict_types=1);

namespace Kode\Session;

use Kode\Session\Contract\Driver;
use Kode\Session\Contract\SessionFactory;
use Kode\Session\Driver\ArrayDriver;
use Kode\Session\Driver\CookieDriver;
use Kode\Session\Driver\DatabaseDriver;
use Kode\Session\Driver\FileDriver;
use Kode\Session\Driver\RedisDriver;
use Kode\Session\Support\SessionId;

/**
 * Session 管理器 - 核心入口类
 * 负责管理驱动和创建 session 实例
 *
 * @author kode
 */
class SessionManager implements SessionFactory
{
    /**
     * 默认驱动
     */
    protected string $defaultDriver = 'file';

    /**
     * 驱动配置（内置驱动的默认配置）
     */
    protected array $drivers = [];

    /**
     * 自定义驱动创建器（通过 extend 注册）
     *
     * @var array<string, callable(array<string, mixed>): Driver>
     */
    protected array $creators = [];

    /**
     * 驱动实例缓存
     */
    protected array $instances = [];

    /**
     * 当前 session 实例
     */
    protected ?Session $session = null;

    /**
     * 配置
     */
    protected array $config;

    /**
     * 构造函数
     *
     * @param array $config 配置数组
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->defaultDriver = $config['default'] ?? 'file';
        $this->drivers = $config['drivers'] ?? [];
    }

    /**
     * 获取 session 实例
     *
     * @param string $id     Session ID
     * @param array  $config 配置参数
     * @return Session
     */
    public function make(string $id, array $config = []): Session
    {
        $driverName = $config['driver'] ?? $this->defaultDriver;
        $driver = $this->getDriver($driverName, $config);
        $name = $config['name'] ?? 'KODE_SESSION';

        return new Session($id, $name, $driver);
    }

    /**
     * 获取驱动实例（按 name + config 缓存，避免不同配置串用同一实例）
     *
     * @param string $name   驱动名称
     * @param array  $config 配置参数
     * @return Driver
     */
    public function getDriver(string $name, array $config = []): Driver
    {
        $key = $name . ':' . md5(serialize($config));

        if (!isset($this->instances[$key])) {
            $this->instances[$key] = $this->createDriver($name, $config);
        }

        return $this->instances[$key];
    }

    /**
     * 创建驱动实例
     *
     * @param string $name   驱动名称
     * @param array  $config 配置参数
     * @return Driver
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    protected function createDriver(string $name, array $config = []): Driver
    {
        // 自定义驱动：通过 extend() 注册的创建器优先
        if (isset($this->creators[$name])) {
            $driverConfig = array_merge($this->drivers[$name] ?? [], $config);
            $driver = ($this->creators[$name])($driverConfig);

            if (!$driver instanceof Driver) {
                throw new \RuntimeException("驱动 [{$name}] 的创建回调必须返回 Driver 实例");
            }

            return $driver;
        }

        $driverConfig = array_merge($this->drivers[$name] ?? [], $config);

        return match ($name) {
            'file' => new FileDriver($driverConfig),
            'redis' => new RedisDriver($driverConfig),
            'cookie' => new CookieDriver($driverConfig),
            'array' => new ArrayDriver($driverConfig),
            'database' => new DatabaseDriver($driverConfig),
            default => throw new \InvalidArgumentException("不支持的驱动: {$name}"),
        };
    }

    /**
     * 从请求创建 session
     *
     * @param array $config 配置参数
     * @return Session
     */
    public function fromRequest(array $config = []): Session
    {
        $id = $this->getSessionIdFromRequest($config);
        $session = $this->make($id, $config);
        $session->start();

        return $session;
    }

    /**
     * 从请求获取 session ID（校验合法性，非法则生成新 ID 防止会话固定 / 路径穿越）
     *
     * @param array $config 配置参数
     * @return string
     */
    protected function getSessionIdFromRequest(array $config): string
    {
        $name = $config['name'] ?? 'KODE_SESSION';
        $idParam = $config['id_param'] ?? 'session_id';

        $candidate = $_COOKIE[$name]
            ?? $_GET[$idParam]
            ?? $_POST[$idParam]
            ?? ($_SERVER['HTTP_X_SESSION_ID'] ?? null);

        if (is_string($candidate) && SessionId::isValid($candidate)) {
            return $candidate;
        }

        return SessionId::generate();
    }

    /**
     * 获取当前 session
     *
     * @return Session|null
     */
    public function getSession(): ?Session
    {
        return $this->session;
    }

    /**
     * 设置当前 session
     *
     * @param Session $session session 实例
     * @return void
     */
    public function setSession(Session $session): void
    {
        $this->session = $session;
    }

    /**
     * 检查 session 是否存在
     *
     * @return bool
     */
    public function hasSession(): bool
    {
        return $this->session !== null;
    }

    /**
     * 创建指定驱动的 session
     *
     * @param string $name   驱动名称
     * @param array  $config 配置参数
     * @return Session
     */
    public function driver(string $name, array $config = []): Session
    {
        return $this->make($this->createId(), $config + ['driver' => $name]);
    }

    /**
     * 创建新 session ID
     *
     * @return string
     */
    public function createId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 获取配置
     *
     * @param string|null $key     配置键
     * @param mixed       $default 默认值
     * @return mixed
     */
    public function getConfig(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? $default;
    }

    /**
     * 设置配置
     *
     * @param string $key   配置键
     * @param mixed  $value 配置值
     * @return void
     */
    public function setConfig(string $key, mixed $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * 注册自定义驱动
     *
     * @param string   $name     驱动名称
     * @param callable $callback 驱动创建回调，接收配置数组，必须返回 Driver 实例
     * @return void
     */
    public function extend(string $name, callable $callback): void
    {
        $this->creators[$name] = $callback;
    }

    /**
     * 触发垃圾回收
     *
     * 中间件按概率调用；也可手动在长周期任务中调用。
     *
     * @param int   $maxLifetime 最大生命周期（秒），0 表示仅清理已过期键、不按文件 mtime 删除
     * @param array $config      可选驱动配置（覆盖默认驱动）
     * @return int 清理的条目数量
     */
    public function gc(int $maxLifetime, array $config = []): int
    {
        $driverName = $config['driver'] ?? $this->defaultDriver;
        $driver = $this->getDriver($driverName, $config);

        return $driver->gc($maxLifetime);
    }

    /**
     * 清除驱动缓存
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->instances = [];
    }

    /**
     * 静态创建（便捷方法）
     *
     * @param array $config 配置参数
     * @return self
     */
    public static function create(array $config = []): self
    {
        return new self($config);
    }
}
