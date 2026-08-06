<?php

declare(strict_types=1);

namespace Kode\Session\Driver;

use Kode\Session\Exception\LockException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * 数据库驱动 - 基于 PDO 的会话存储
 * 支持 SQLite / MySQL / PostgreSQL，适合已有数据库基础设施、希望会话入库的场景。
 *
 * 实现要点：
 * - 每行存储一个键：(id, name) 主键，payload 为 wrap 后的 JSON（启用加密时为密文）
 * - upsert 采用「先 UPDATE 后 INSERT」的跨库兼容写法，不依赖特定方言
 * - 分布式锁基于独立锁表（INSERT 抢占 / 过期可重新认领），跨数据库通用
 * - 连接惰性创建，进程内复用同一个 PDO 实例（SQLite 内存库因此可跨调用存活）
 *
 * @author kode
 */
class DatabaseDriver extends AbstractDriver
{
    /**
     * PDO 连接实例
     */
    protected ?PDO $pdo = null;

    /**
     * 会话表名
     */
    protected string $table;

    /**
     * 锁表名
     */
    protected string $lockTable;

    /**
     * 锁超时（秒）
     */
    protected int $lockTimeout;

    /**
     * 持有的锁令牌
     *
     * @var array<string, string>
     */
    protected array $lockTokens = [];

    /**
     * 表是否已初始化
     */
    protected bool $initialized = false;

    /**
     * 构造函数
     *
     * @param array<string, mixed> $config 配置数组
     * @throws RuntimeException 缺少 PDO 扩展或必需配置时
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        if (!extension_loaded('pdo')) {
            throw new RuntimeException('DatabaseDriver 需要 PDO 扩展（ext-pdo）');
        }

        if (empty($config['dsn'])) {
            throw new RuntimeException("DatabaseDriver 必须配置 'dsn'（如 sqlite:/path 或 mysql:host=...;dbname=...）");
        }

        $this->table = (string) ($config['table'] ?? 'kode_sessions');
        $this->lockTable = (string) ($config['lock_table'] ?? 'kode_session_locks');
        $this->lockTimeout = (int) ($config['lock_timeout'] ?? 10);
    }

    /**
     * 获取 PDO 连接（惰性创建并初始化表结构）
     */
    protected function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                $this->config['dsn'],
                $this->config['username'] ?? null,
                $this->config['password'] ?? null,
                array_merge([
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ], $this->config['options'] ?? [])
            );

            $this->initSchema();
        }

        return $this->pdo;
    }

    /**
     * 初始化会话表与锁表（幂等）
     */
    protected function initSchema(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->getPdo()->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id VARCHAR(191) NOT NULL,
                name VARCHAR(191) NOT NULL,
                payload TEXT NOT NULL,
                expire INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id, name)
            )',
            $this->quoteIdentifier($this->table)
        ));

        $this->getPdo()->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id VARCHAR(191) NOT NULL PRIMARY KEY,
                token VARCHAR(64) NOT NULL,
                expire INT NOT NULL
            )',
            $this->quoteIdentifier($this->lockTable)
        ));

        $this->initialized = true;
    }

    /**
     * 安全引用标识符（表名来自配置，需做基本净化）
     */
    protected function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '', $name) . '`';
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
        $row = $this->fetchRow($this->validateId($id), $name);

        if ($row === null) {
            return $default;
        }

        if ($this->isRowExpired($row)) {
            $this->delete($id, $name);

            return $default;
        }

        return $this->unwrap($this->decodeRow($row), $default);
    }

    /**
     * 设置 session 值
     *
     * @param string $id       Session ID
     * @param string $name     键名
     * @param mixed  $value     值
     * @param int    $lifetime  生命周期（秒）
     */
    #[\Override]
    public function set(string $id, string $name, mixed $value, int $lifetime = 0): bool
    {
        $id = $this->validateId($id);
        $wrapped = $this->wrap($value, $lifetime);

        return $this->upsert($id, $name, $this->encodeRow($wrapped));
    }

    /**
     * 批量写入（事务包裹）
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
        $pdo = $this->getPdo();
        $pdo->beginTransaction();

        try {
            foreach ($values as $name => $value) {
                $wrapped = $this->wrap($value, $lifetime);
                $this->upsert($id, (string) $name, $this->encodeRow($wrapped));
            }

            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();

            return false;
        }
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
        $id = $this->validateId($id);

        $stmt = $this->getPdo()->prepare(sprintf(
            'DELETE FROM %s WHERE id = ? AND name = ?',
            $this->quoteIdentifier($this->table)
        ));
        $stmt->execute([$id, $name]);

        return true;
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
        $row = $this->fetchRow($this->validateId($id), $name);

        return $row !== null && !$this->isRowExpired($row);
    }

    /**
     * 会话是否存在
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function exists(string $id): bool
    {
        $stmt = $this->getPdo()->prepare(sprintf(
            'SELECT 1 FROM %s WHERE id = ? LIMIT 1',
            $this->quoteIdentifier($this->table)
        ));
        $stmt->execute([$this->validateId($id)]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * 清空指定 session 的所有数据
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function clear(string $id): bool
    {
        $stmt = $this->getPdo()->prepare(sprintf(
            'DELETE FROM %s WHERE id = ?',
            $this->quoteIdentifier($this->table)
        ));
        $stmt->execute([$this->validateId($id)]);

        return true;
    }

    /**
     * 开启 session（获取数据库锁）
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function open(string $id): bool
    {
        return $this->acquireLock($id);
    }

    /**
     * 关闭 session（释放数据库锁）
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
        $this->clear($id);
        $this->releaseLock($id);

        return true;
    }

    /**
     * 垃圾回收：清理已过期的键
     *
     * @param int $maxLifetime 最大生命周期（秒）
     * @return int 清理条目数
     */
    #[\Override]
    public function gc(int $maxLifetime): int
    {
        $count = $this->getPdo()->exec(sprintf(
            'DELETE FROM %s WHERE expire > 0 AND %d > expire',
            $this->quoteIdentifier($this->table),
            time()
        ));

        return (int) $count;
    }

    /**
     * 迁移数据到新 ID
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
        $pdo = $this->getPdo();

        if ($delete) {
            // 移动：直接改写主键归属
            $stmt = $pdo->prepare(sprintf(
                'UPDATE %s SET id = ? WHERE id = ?',
                $this->quoteIdentifier($this->table)
            ));
            $stmt->execute([$toId, $fromId]);
        } else {
            // 复制：保留原数据
            $stmt = $pdo->prepare(sprintf(
                'INSERT INTO %s (id, name, payload, expire) SELECT ?, name, payload, expire FROM %s WHERE id = ?',
                $this->quoteIdentifier($this->table),
                $this->quoteIdentifier($this->table)
            ));
            $stmt->execute([$toId, $fromId]);
        }

        // 释放原 ID 上的残留锁
        if (isset($this->lockTokens[$fromId])) {
            $this->releaseLock($fromId);
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
        $stmt = $this->getPdo()->prepare(sprintf(
            'SELECT name, payload, expire FROM %s WHERE id = ?',
            $this->quoteIdentifier($this->table)
        ));
        $stmt->execute([$id]);

        $result = [];

        foreach ($stmt->fetchAll() as $row) {
            if ($this->isRowExpired($row)) {
                continue;
            }

            $result[$row['name']] = $this->unwrap($this->decodeRow($row), null);
        }

        return $result;
    }

    /**
     * 获取分布式锁（锁表抢占，过期可重新认领）
     *
     * @param string   $id      Session ID
     * @param int|null $timeout 超时时间
     */
    #[\Override]
    public function acquireLock(string $id, ?int $timeout = null): bool
    {
        $id = $this->validateId($id);
        $timeout = $timeout ?? $this->lockTimeout;
        $token = bin2hex(random_bytes(16));
        $start = time();

        while (true) {
            if ($this->tryClaimLock($id, $token, time() + $timeout)) {
                $this->lockTokens[$id] = $token;

                return true;
            }

            if (time() - $start >= $timeout) {
                return false;
            }

            usleep(10000);
        }
    }

    /**
     * 尝试认领锁（插入或抢占过期锁）
     */
    protected function tryClaimLock(string $id, string $token, int $expire): bool
    {
        $pdo = $this->getPdo();
        $table = $this->quoteIdentifier($this->lockTable);

        try {
            $stmt = $pdo->prepare("INSERT INTO {$table} (id, token, expire) VALUES (?, ?, ?)");

            return $stmt->execute([$id, $token, $expire]);
        } catch (PDOException $e) {
            // 唯一冲突：锁已被持有，尝试认领已过期者
            if ($e->getCode() === '23000') {
                $stmt = $pdo->prepare("UPDATE {$table} SET token = ?, expire = ? WHERE id = ? AND expire < ?");

                return $stmt->execute([$token, $expire, $id, time()]);
            }

            throw $e;
        }
    }

    /**
     * 释放分布式锁（仅释放自己持有的锁）
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

        $stmt = $this->getPdo()->prepare(sprintf(
            'DELETE FROM %s WHERE id = ? AND token = ?',
            $this->quoteIdentifier($this->lockTable)
        ));
        $stmt->execute([$id, $token]);

        unset($this->lockTokens[$id]);

        return true;
    }

    /**
     * 读取单行（返回 name/payload/expire 关联数组或 null）
     *
     * @return array{name: string, payload: string, expire: int}|null
     */
    protected function fetchRow(string $id, string $name): ?array
    {
        $stmt = $this->getPdo()->prepare(sprintf(
            'SELECT name, payload, expire FROM %s WHERE id = ? AND name = ?',
            $this->quoteIdentifier($this->table)
        ));
        $stmt->execute([$id, $name]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * 判断行是否已过期
     *
     * @param array{name: string, payload: string, expire: int} $row
     */
    protected function isRowExpired(array $row): bool
    {
        $expire = (int) ($row['expire'] ?? 0);

        return $expire > 0 && time() > $expire;
    }

    /**
     * 将数据库行解码为 wrap 结构
     *
     * @param array{name: string, payload: string, expire: int} $row
     * @return array{data: mixed, expire: int}
     */
    protected function decodeRow(array $row): array
    {
        $data = json_decode($row['payload'], true);

        if (!is_array($data)) {
            return ['data' => null, 'expire' => 0];
        }

        return $data;
    }

    /**
     * 将 wrap 结构编码为可存储字符串
     *
     * @param array{data: mixed, expire: int} $wrapped
     */
    protected function encodeRow(array $wrapped): string
    {
        return json_encode($wrapped, JSON_THROW_ON_ERROR);
    }

    /**
     * upsert 单键（先 UPDATE，受影响行为 0 时 INSERT）
     */
    protected function upsert(string $id, string $name, string $payload): bool
    {
        $pdo = $this->getPdo();
        $expire = $this->extractExpire($payload);

        $update = $pdo->prepare(sprintf(
            'UPDATE %s SET payload = ?, expire = ? WHERE id = ? AND name = ?',
            $this->quoteIdentifier($this->table)
        ));
        $update->execute([$payload, $expire, $id, $name]);

        if ($update->rowCount() > 0) {
            return true;
        }

        $insert = $pdo->prepare(sprintf(
            'INSERT INTO %s (id, name, payload, expire) VALUES (?, ?, ?, ?)',
            $this->quoteIdentifier($this->table)
        ));

        try {
            $insert->execute([$id, $name, $payload, $expire]);
        } catch (PDOException $e) {
            // 并发插入冲突（非 SQLite 方言下 IGNORE 不生效时），回退为再 UPDATE 一次
            if ($e->getCode() === '23000') {
                $update->execute([$payload, $expire, $id, $name]);

                return true;
            }

            throw $e;
        }

        return true;
    }

    /**
     * 从 payload 中提取 expire 字段（用于索引列）
     */
    protected function extractExpire(string $payload): int
    {
        $data = json_decode($payload, true);

        return is_array($data) ? (int) ($data['expire'] ?? 0) : 0;
    }

    /**
     * 释放 PDO 连接（析构）
     */
    public function __destruct()
    {
        $this->pdo = null;
    }
}
