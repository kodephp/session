<?php

declare(strict_types=1);

namespace Kode\Session\Driver;

use Kode\Session\Exception\LockException;
use Predis\Client as PredisClient;
use RuntimeException;

/**
 * Redis 驱动 - 支持分布式 session 存储
 * 支持 phpredis 扩展和 predis 库两种方式
 * 适合多机器部署的生产环境
 *
 * 实现要点：
 * - 每条数据以 `prefix:{id}:{name}` 独立存储，便于按 session 维度批量清理与迁移
 * - 统一复用 AbstractDriver 的 wrap/unwrap/isExpired，避免各驱动重复实现
 * - 兼容 phpredis `get` 返回 false（未命中）与 predis 返回 null 的差异
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
     *
     * @var array<string, string>
     */
    protected array $lockTokens = [];

    /**
     * 构造函数
     *
     * @param array<string, mixed> $config 配置数组
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->redisConfig = $config['redis'] ?? [];
        $this->lockPrefix = ($config['lock_prefix'] ?? 'lock:') . $this->prefix;
        $this->lockTimeout = (int) ($config['lock_timeout'] ?? 10);
    }

    /**
     * 获取 session 值
     *
     * @param string $id      Session ID
     * @param string $name    键名
     * @param mixed  $default 默认值
     */
    #[\Override]
    public function get(string $id, string $name, mixed $default = null): mixed
    {
        $payload = $this->readValue($this->getKey($this->validateId($id), $name));

        if ($payload === null) {
            return $default;
        }

        if ($this->isExpired($payload)) {
            $this->delete($id, $name);

            return $default;
        }

        return $this->unwrap($payload, $default);
    }

    /**
     * 设置 session 值
     *
     * @param string $id       Session ID
     * @param string $name     键名
     * @param mixed  $value     值
     * @param int    $lifetime  生命周期（秒），0 表示跟随驱动默认策略
     */
    #[\Override]
    public function set(string $id, string $name, mixed $value, int $lifetime = 0): bool
    {
        $key = $this->getKey($this->validateId($id), $name);
        $payload = $this->wrap($value, $lifetime);
        $ttl = $this->resolveTtl($lifetime);

        return $this->getRedis()->setex($key, $ttl, $this->encode($payload)) !== false;
    }

    /**
     * 批量写入（单次网络往返）
     *
     * @param string               $id       Session ID
     * @param array<string, mixed> $values   键值对
     * @param int                  $lifetime 生命周期
     */
    #[\Override]
    public function setMultiple(string $id, array $values, int $lifetime = 0): bool
    {
        if ($values === []) {
            return true;
        }

        $id = $this->validateId($id);
        $ttl = $this->resolveTtl($lifetime);
        $pipeline = [];

        foreach ($values as $name => $value) {
            $pipeline[$this->getKey($id, (string) $name)] = $this->encode($this->wrap($value, $lifetime));
        }

        $redis = $this->getRedis();

        // 使用事务 / pipeline 提升批量写入性能
        if (method_exists($redis, 'multi')) {
            /** @var \Redis|\Predis\Client $tx */
            $tx = $redis->multi();

            foreach ($pipeline as $key => $payload) {
                $tx->setex($key, $ttl, $payload);
            }

            $tx->exec();

            return true;
        }

        foreach ($pipeline as $key => $payload) {
            if ($redis->setex($key, $ttl, $payload) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * 删除 session 值
     *
     * @param string $id   Session ID
     * @param string $name 键名
     */
    #[\Override]
    public function delete(string $id, string $name): bool
    {
        $key = $this->getKey($this->validateId($id), $name);

        return $this->getRedis()->del([$key]) >= 0;
    }

    /**
     * 检查键是否存在（值存在但为 null 也算存在）
     *
     * @param string $id   Session ID
     * @param string $name 键名
     */
    #[\Override]
    public function has(string $id, string $name): bool
    {
        $payload = $this->readValue($this->getKey($this->validateId($id), $name));

        if ($payload === null) {
            return false;
        }

        return !$this->isExpired($payload);
    }

    /**
     * 会话在 Redis 中是否存在（只要有一条键即视为存在）
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function exists(string $id): bool
    {
        return $this->scanKeys($this->prefix . $this->validateId($id) . ':*') !== [];
    }

    /**
     * 清空指定 session 的所有数据
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function clear(string $id): bool
    {
        $keys = $this->scanKeys($this->prefix . $this->validateId($id) . ':*');

        if ($keys === []) {
            return true;
        }

        return $this->getRedis()->del($keys) >= 0;
    }

    /**
     * 开启 session（获取分布式锁）
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function open(string $id): bool
    {
        return $this->acquireLock($id);
    }

    /**
     * 关闭 session（释放分布式锁）
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function close(string $id): bool
    {
        return $this->releaseLock($id);
    }

    /**
     * 销毁 session
     *
     * @param string $id Session ID
     */
    #[\Override]
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
    #[\Override]
    public function gc(int $maxLifetime): int
    {
        $pattern = $this->prefix . '*';
        $keys = $this->scanKeys($pattern);
        $count = 0;
        $now = time();

        foreach ($keys as $key) {
            $payload = $this->readValue($key);

            if ($payload === null) {
                continue;
            }

            if ($this->isExpired($payload)) {
                $this->getRedis()->del([$key]);
                $count++;
            } elseif ($maxLifetime > 0 && $this->getExpireAt($key) > 0 && $this->getExpireAt($key) < $now - $maxLifetime) {
                // 兜底：基于 TTL 的陈旧键清理（极少见）
                $count++;
            }
        }

        return $count;
    }

    /**
     * 迁移数据到新 ID（按键逐条复制到新前缀后删除旧键）
     *
     * @param string $fromId 原 Session ID
     * @param string $toId   新 Session ID
     * @param bool   $delete 是否删除原数据
     */
    #[\Override]
    public function migrate(string $fromId, string $toId, bool $delete = true): bool
    {
        if ($fromId === $toId) {
            return true;
        }

        $fromId = $this->validateId($fromId);
        $toId = $this->validateId($toId);
        $keys = $this->scanKeys($this->prefix . $fromId . ':*');

        foreach ($keys as $key) {
            $name = substr($key, strlen($this->prefix . $fromId . ':'));
            $payload = $this->readValue($key);

            if ($payload === null) {
                continue;
            }

            $this->getRedis()->setex($this->getKey($toId, $name), $this->resolveTtl(0), $this->encode($payload));
        }

        if ($delete) {
            $this->clear($fromId);
        }

        return true;
    }

    /**
     * 获取 session 所有数据（已解包并剔除过期项）
     *
     * @param string $id Session ID
     * @return array<string, mixed>
     */
    #[\Override]
    public function all(string $id): array
    {
        $id = $this->validateId($id);
        $keys = $this->scanKeys($this->prefix . $id . ':*');
        $result = [];

        foreach ($keys as $key) {
            $payload = $this->readValue($key);

            if ($payload === null || $this->isExpired($payload)) {
                continue;
            }

            $name = substr($key, strlen($this->prefix . $id . ':'));
            $result[$name] = $this->unwrap($payload);
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
     * @param string      $host     主机
     * @param int         $port     端口
     * @param string|null $password 密码
     * @param int         $database 数据库
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
            throw new RuntimeException('Redis 连接失败: ' . $e->getMessage(), 0, $e);
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
     * 读取并解码单条数据，未命中或解码失败返回 null
     *
     * @param string $key Redis 键
     * @return array{data: mixed, expire: int}|null
     */
    protected function readValue(string $key): ?array
    {
        $raw = $this->getRedis()->get($key);

        if ($raw === null || $raw === false) {
            return null;
        }

        $payload = $this->decode($raw);

        return is_array($payload) ? $payload : null;
    }

    /**
     * 获取缓存键
     *
     * @param string $id   Session ID
     * @param string $name 键名
     */
    protected function getKey(string $id, string $name): string
    {
        return $this->prefix . $id . ':' . $name;
    }

    /**
     * 解析实际 Redis TTL（lifetime > 0 用 lifetime，否则用默认过期或兜底 30 天）
     */
    protected function resolveTtl(int $lifetime): int
    {
        if ($lifetime > 0) {
            return $lifetime;
        }

        if ($this->defaultLifetime > 0) {
            return $this->defaultLifetime;
        }

        return 86400 * 30;
    }

    /**
     * 编码负载
     *
     * @param array{data: mixed, expire: int} $payload
     */
    protected function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * 解码负载
     *
     * @return array{data: mixed, expire: int}|null
     */
    protected function decode(string $value): ?array
    {
        try {
            $data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * 获取键的过期时间戳（0 表示无过期）
     */
    protected function getExpireAt(string $key): int
    {
        $ttl = $this->getRedis()->ttl($key);

        if (!is_int($ttl) || $ttl < 0) {
            return 0;
        }

        return $ttl > 0 ? time() + $ttl : 0;
    }

    /**
     * 扫描所有匹配的键
     *
     * @return array<int, string>
     */
    protected function scanKeys(string $pattern): array
    {
        $keys = [];
        $redis = $this->getRedis();

        if ($this->usePhpRedis) {
            $cursor = 0;
            do {
                /** @var array{0: int, 1: list<string>} $result */
                $result = $redis->scan($cursor, $pattern, 100);

                if (!is_array($result) || $result === [0 => 0, 1 => []]) {
                    break;
                }

                $cursor = $result[0];
                $keys = array_merge($keys, $result[1]);
            } while ($cursor !== 0);
        } else {
            $cursor = '0';
            do {
                /** @var array{0: string, 1: list<string>} $result */
                $result = $redis->scan($cursor, 'MATCH', $pattern, 'COUNT', 100);

                if (!is_array($result)) {
                    break;
                }

                $cursor = (string) $result[0];
                $keys = array_merge($keys, $result[1]);
            } while ($cursor !== '0');
        }

        return $keys;
    }

    /**
     * 获取分布式锁
     *
     * @param string   $id      Session ID
     * @param int|null $timeout 超时时间
     */
    #[\Override]
    public function acquireLock(string $id, ?int $timeout = null): bool
    {
        $id = $this->validateId($id);
        $lockKey = $this->lockPrefix . $id;
        $lockTimeout = $timeout ?? $this->lockTimeout;
        $token = bin2hex(random_bytes(16));

        $start = time();

        while (true) {
            if ($this->usePhpRedis) {
                $acquired = $this->getRedis()->set($lockKey, $token, ['NX', 'EX' => $lockTimeout]);
            } else {
                $acquired = $this->getRedis()->set($lockKey, $token, 'EX', $lockTimeout, 'NX');
            }

            // phpredis 成功返回 true；predis 成功返回字符串 token
            $ok = $acquired === true || (is_string($acquired) && $acquired !== '');

            if ($ok) {
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
     * 释放分布式锁（Lua 脚本保证仅释放自己持有的锁）
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function releaseLock(string $id): bool
    {
        $id = $this->validateId($id);
        $token = $this->lockTokens[$id] ?? null;

        if ($token === null) {
            return true;
        }

        $lockKey = $this->lockPrefix . $id;
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
