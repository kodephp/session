<?php

declare(strict_types=1);

namespace Kode\Session\Driver;

use Predis\Client as PredisClient;
use RuntimeException;

/**
 * Redis 驱动 - 支持分布式 session 存储
 * 支持 phpredis 扩展和 predis 库两种方式
 * 适合多机器部署的生产环境
 *
 * @author kode
 */
class RedisDriver extends AbstractDriver
{
    /**
     * Redis 连接实例（phpredis 或 predis）
     */
    protected mixed $redis = null;

    /**
     * 是否使用 phpredis 扩展
     */
    protected bool $usePhpRedis = false;

    /**
     * Redis 配置
     */
    protected array $redisConfig;

    /**
     * 锁前缀
     */
    protected string $lockPrefix;

    /**
     * 锁超时时间（秒）
     */
    protected int $lockTimeout;

    /**
     * 锁令牌存储
     */
    protected array $lockTokens = [];

    /**
     * 构造函数
     *
     * @param array $config 配置数组
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->redisConfig = $config['redis'] ?? [];
        $this->lockPrefix = ($config['lock_prefix'] ?? 'lock:') . $this->prefix;
        $this->lockTimeout = $config['lock_timeout'] ?? 10;
    }

    /**
     * 获取 session 值
     *
     * @param string $id     Session ID
     * @param string $name   键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get(string $id, string $name, mixed $default = null): mixed
    {
        $key = $this->getKey($id, $name);
        $value = $this->getRedis()->get($key);

        if ($value === null) {
            return $default;
        }

        $data = $this->unserialize($value);

        if ($this->isExpired($data)) {
            $this->delete($id, $name);
            return $default;
        }

        return $data['data'] ?? $default;
    }

    /**
     * 设置 session 值
     *
     * @param string $id        Session ID
     * @param string $name       键名
     * @param mixed  $value      值
     * @param int    $lifetime   生命周期（秒），0表示永久
     * @return bool
     */
    public function set(string $id, string $name, mixed $value, int $lifetime = 0): bool
    {
        $key = $this->getKey($id, $name);
        $data = [
            'data' => $value,
            'expire' => $lifetime > 0 ? time() + $lifetime : 0,
        ];

        $serialized = $this->serializeData($data);
        $ttl = $lifetime > 0 ? $lifetime : 86400 * 30;

        return $this->getRedis()->setex($key, $ttl, $serialized) !== false;
    }

    /**
     * 删除 session 值
     *
     * @param string $id   Session ID
     * @param string $name 键名
     * @return bool
     */
    public function delete(string $id, string $name): bool
    {
        $key = $this->getKey($id, $name);
        return $this->getRedis()->del([$key]) >= 0;
    }

    /**
     * 检查 session 是否存在
     *
     * @param string $id   Session ID
     * @param string $name 键名
     * @return bool
     */
    public function has(string $id, string $name): bool
    {
        $key = $this->getKey($id, $name);
        return $this->getRedis()->exists($key) > 0;
    }

    /**
     * 清空指定 session 的所有数据
     *
     * @param string $id Session ID
     * @return bool
     */
    public function clear(string $id): bool
    {
        $pattern = $this->prefix . $id . ':*';
        $keys = $this->scanKeys($pattern);

        if (empty($keys)) {
            return true;
        }

        return $this->getRedis()->del($keys) >= 0;
    }

    /**
     * 开启 session（获取分布式锁）
     *
     * @param string $id Session ID
     * @return bool
     */
    public function open(string $id): bool
    {
        return $this->acquireLock($id);
    }

    /**
     * 关闭 session（释放分布式锁）
     *
     * @param string $id Session ID
     * @return bool
     */
    public function close(string $id): bool
    {
        return $this->releaseLock($id);
    }

    /**
     * 销毁 session
     *
     * @param string $id Session ID
     * @return bool
     */
    public function destroy(string $id): bool
    {
        $this->releaseLock($id);
        return $this->clear($id);
    }

    /**
     * 垃圾回收
     *
     * @param int $maxLifetime 最大生命周期
     * @return int 清理数量
     */
    public function gc(int $maxLifetime): int
    {
        $pattern = $this->prefix . '*';
        $keys = $this->scanKeys($pattern);
        $count = 0;
        $now = time();

        foreach ($keys as $key) {
            $value = $this->getRedis()->get($key);

            if ($value === null) {
                continue;
            }

            $data = $this->unserialize($value);

            if (isset($data['expire']) && $data['expire'] > 0 && $data['expire'] < $now) {
                $this->getRedis()->del([$key]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * 获取 session 所有数据
     *
     * @param string $id Session ID
     * @return array
     */
    public function all(string $id): array
    {
        $pattern = $this->prefix . $id . ':*';
        $keys = $this->scanKeys($pattern);
        $result = [];

        foreach ($keys as $key) {
            $value = $this->getRedis()->get($key);

            if ($value === null) {
                continue;
            }

            $data = $this->unserialize($value);
            $name = str_replace($this->prefix . $id . ':', '', $key);
            $result[$name] = $data['data'] ?? null;
        }

        return $result;
    }

    /**
     * 获取 Redis 连接
     *
     * @return mixed
     */
    protected function getRedis(): mixed
    {
        if ($this->redis === null) {
            $this->redis = $this->createConnection();
        }

        return $this->redis;
    }

    /**
     * 创建 Redis 连接
     *
     * @return mixed
     * @throws RuntimeException
     */
    protected function createConnection(): mixed
    {
        $host = $this->redisConfig['host'] ?? '127.0.0.1';
        $port = $this->redisConfig['port'] ?? 6379;
        $password = $this->redisConfig['password'] ?? null;
        $database = $this->redisConfig['database'] ?? 0;

        if (extension_loaded('redis') && class_exists('Redis')) {
            $this->usePhpRedis = true;
            return $this->createPhpRedisConnection($host, (int) $port, $password, (int) $database);
        }

        if (class_exists(PredisClient::class)) {
            return $this->createPredisConnection($host, (int) $port, $password, (int) $database);
        }

        throw new RuntimeException(
            '需要安装 phpredis 扩展或 predis/predis 包。' . PHP_EOL .
            '安装命令: composer require predis/predis'
        );
    }

    /**
     * 创建 phpredis 连接
     *
     * @param string  $host     主机
     * @param int     $port     端口
     * @param string|null $password 密码
     * @param int     $database 数据库
     * @return \Redis
     */
    protected function createPhpRedisConnection(string $host, int $port, ?string $password, int $database): mixed
    {
        /** @var \Redis $redis */
        $redis = new \Redis();
        $timeout = $this->redisConfig['timeout'] ?? 0.0;

        try {
            if ($timeout > 0) {
                $redis->connect($host, $port, $timeout);
            } else {
                $redis->connect($host, $port);
            }

            if ($password !== null) {
                $redis->auth($password);
            }

            $redis->select($database);
        } catch (\RedisException $e) {
            throw new RuntimeException("Redis 连接失败: " . $e->getMessage(), 0, $e);
        }

        return $redis;
    }

    /**
     * 创建 Predis 连接
     *
     * @param string      $host     主机
     * @param int         $port     端口
     * @param string|null $password 密码
     * @param int         $database 数据库
     * @return PredisClient
     */
    protected function createPredisConnection(string $host, int $port, ?string $password, int $database): PredisClient
    {
        $parameters = [
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
            'database' => $database,
        ];

        if ($password !== null) {
            $parameters['password'] = $password;
        }

        $options = [];

        if (isset($this->redisConfig['timeout'])) {
            $options['timeout'] = $this->redisConfig['timeout'];
        }

        return new PredisClient($parameters, $options);
    }

    /**
     * 获取缓存键
     *
     * @param string $id   Session ID
     * @param string $name 键名
     * @return string
     */
    protected function getKey(string $id, string $name): string
    {
        return $this->prefix . $id . ':' . $name;
    }

    /**
     * 序列化数据
     *
     * @param array $data 数据
     * @return string
     */
    protected function serializeData(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * 反序列化数据
     *
     * @param string $value 值
     * @return array
     */
    protected function unserialize(string $value): array
    {
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * 检查数据是否过期
     *
     * @param array $data 数据
     * @return bool
     */
    protected function isExpired(array $data): bool
    {
        if (!isset($data['expire']) || $data['expire'] === 0) {
            return false;
        }

        return time() > $data['expire'];
    }

    /**
     * 扫描所有匹配的键
     *
     * @param string $pattern 模式
     * @return array
     */
    protected function scanKeys(string $pattern): array
    {
        $keys = [];

        if ($this->usePhpRedis) {
            $cursor = 0;
            do {
                $result = $this->getRedis()->scan($cursor, $pattern, 100);
                if ($result === false) {
                    break;
                }
                [$cursor, $matchKeys] = $result;
                $keys = array_merge($keys, $matchKeys);
            } while ($cursor !== 0);
        } else {
            $cursor = '0';
            do {
                $result = $this->getRedis()->scan($cursor, 'MATCH', $pattern, 'COUNT', 100);
                if ($result === null) {
                    break;
                }
                [$cursor, $matchKeys] = $result;
                $keys = array_merge($keys, $matchKeys);
            } while ($cursor !== '0');
        }

        return $keys;
    }

    /**
     * 获取分布式锁
     *
     * @param string $id     Session ID
     * @param int|null $timeout 超时时间
     * @return bool
     */
    public function acquireLock(string $id, int $timeout = null): bool
    {
        $lockKey = $this->lockPrefix . $id;
        $lockTimeout = $timeout ?? $this->lockTimeout;
        $token = bin2hex(random_bytes(16));

        $start = time();

        while (true) {
            if ($this->usePhpRedis) {
                $acquired = $this->getRedis()->set(
                    $lockKey,
                    $token,
                    ['NX', 'EX' => $lockTimeout]
                );
            } else {
                $acquired = $this->getRedis()->set($lockKey, $token, 'EX', $lockTimeout, 'NX');
            }

            if ($acquired) {
                $this->lockTokens[$id] = $token;
                return true;
            }

            if (time() - $start >= $lockTimeout) {
                return false;
            }

            usleep(10000);
        }
    }

    /**
     * 释放分布式锁
     *
     * @param string $id Session ID
     * @return bool
     */
    public function releaseLock(string $id): bool
    {
        $lockKey = $this->lockPrefix . $id;
        $token = $this->lockTokens[$id] ?? null;

        if ($token === null) {
            return true;
        }

        $script = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
else
    return 0
end
LUA;
        $this->getRedis()->eval($script, 1, $lockKey, $token);

        unset($this->lockTokens[$id]);
        return true;
    }

    /**
     * 关闭 Redis 连接
     *
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->redis !== null) {
            if ($this->usePhpRedis && method_exists($this->redis, 'close')) {
                $this->redis->close();
            } elseif (!$this->usePhpRedis && method_exists($this->redis, 'disconnect')) {
                $this->redis->disconnect();
            }
            $this->redis = null;
        }
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
