<?php

declare(strict_types=1);

namespace Kode\Session;

use Kode\Session\Contract\Driver;
use Kode\Session\Contract\SessionFactory;
use Kode\Session\Driver\CookieDriver;
use Kode\Session\Driver\FileDriver;
use Kode\Session\Driver\RedisDriver;

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
     * 驱动配置
     */
    protected array $drivers = [];

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
     * 获取驱动实例
     *
     * @param string $name   驱动名称
     * @param array  $config 配置参数
     * @return Driver
     */
    public function getDriver(string $name, array $config = []): Driver
    {
        if (!isset($this->instances[$name])) {
            $this->instances[$name] = $this->createDriver($name, $config);
        }

        return $this->instances[$name];
    }

    /**
     * 创建驱动实例
     *
     * @param string $name   驱动名称
     * @param array  $config 配置参数
     * @return Driver
     * @throws \InvalidArgumentException
     */
    protected function createDriver(string $name, array $config = []): Driver
    {
        $driverConfig = $this->drivers[$name] ?? [];

        if (isset($config['driver'])) {
            $driverConfig = array_merge($driverConfig, $config);
        }

        return match ($name) {
            'file' => new FileDriver($driverConfig),
            'redis' => new RedisDriver($driverConfig),
            'cookie' => new CookieDriver($driverConfig),
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
     * 从请求获取 session ID
     *
     * @param array $config 配置参数
     * @return string
     */
    protected function getSessionIdFromRequest(array $config): string
    {
        $name = $config['name'] ?? 'KODE_SESSION';
        $idParam = $config['id_param'] ?? 'session_id';

        if (!empty($_COOKIE[$name])) {
            return $_COOKIE[$name];
        }

        if (!empty($_GET[$idParam])) {
            return $_GET[$idParam];
        }

        if (!empty($_POST[$idParam])) {
            return $_POST[$idParam];
        }

        if (!empty($_SERVER['HTTP_X_SESSION_ID'])) {
            return $_SERVER['HTTP_X_SESSION_ID'];
        }

        return bin2hex(random_bytes(16));
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
     * 注册驱动
     *
     * @param string   $name     驱动名称
     * @param callable $callback 驱动创建回调
     * @return void
     */
    public function extend(string $name, callable $callback): void
    {
        $this->drivers[$name] = $callback;
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
