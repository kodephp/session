<?php

declare(strict_types=1);

namespace Kode\Session\Driver;

use Kode\Session\Exception\LockException;

/**
 * 文件驱动 - 基于本地文件系统存储 session
 * 适合单机部署或开发环境使用
 *
 * 实现要点：
 * - 写入使用「临时文件 + rename」保证原子性，避免读到半截数据
 * - 锁句柄常驻，flock 在 releaseLock 前始终有效（不再随函数返回被 GC 释放）
 * - 存储体为 `<?php exit;` 守卫 + serialize 负载，读取不经过 include，规避 opcache 陈旧
 *
 * @author kode
 */
class FileDriver extends AbstractDriver
{
    /**
     * 文件头守卫，防止会话文件被 Web 直接解析输出
     */
    private const string GUARD = '<?php exit; ?>';

    /**
     * 旧版本（<= 2.x）文件头
     */
    private const string LEGACY_GUARD = '<?php return ';

    /**
     * 锁目录
     */
    protected string $lockPath;

    /**
     * 目录权限
     */
    protected int $dirMode;

    /**
     * 文件权限
     */
    protected int $fileMode;

    /**
     * 默认锁等待超时（秒）
     */
    protected int $lockTimeout;

    /**
     * 持有中的锁句柄
     *
     * @var array<string, resource>
     */
    protected array $handles = [];

    /**
     * 锁重入计数
     *
     * @var array<string, int>
     */
    protected array $lockDepth = [];

    /**
     * 持锁期间的负载缓存（独占持锁时才可信）
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $cache = [];

    /**
     * 构造函数
     *
     * @param array<string, mixed> $config 配置数组
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->lockPath = rtrim((string) ($config['lock_path'] ?? $this->path), '/\\') . DIRECTORY_SEPARATOR . 'locks';
        $this->dirMode = (int) ($config['dir_mode'] ?? 0700);
        $this->fileMode = (int) ($config['file_mode'] ?? 0600);
        $this->lockTimeout = (int) ($config['lock_timeout'] ?? 10);

        $this->ensureDirectory($this->path);
        $this->ensureDirectory($this->lockPath);
    }

    /**
     * 析构：释放所有未显式释放的锁
     */
    public function __destruct()
    {
        foreach (array_keys($this->handles) as $id) {
            $this->forceReleaseLock($id);
        }
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
        $payload = $this->read($id);

        if (!isset($payload[$name])) {
            return $default;
        }

        if ($this->isExpired($payload[$name])) {
            $this->delete($id, $name);

            return $default;
        }

        return $this->unwrap($payload[$name], $default);
    }

    /**
     * 设置 session 值
     *
     * @param string $id       Session ID
     * @param string $name     键名
     * @param mixed  $value    值
     * @param int    $lifetime 生命周期（秒）
     */
    #[\Override]
    public function set(string $id, string $name, mixed $value, int $lifetime = 0): bool
    {
        $payload = $this->read($id);
        $payload[$name] = $this->wrap($value, $lifetime);

        return $this->write($id, $payload);
    }

    /**
     * 批量写入（单次落盘）
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

        $payload = $this->read($id);

        foreach ($values as $name => $value) {
            $payload[(string) $name] = $this->wrap($value, $lifetime);
        }

        return $this->write($id, $payload);
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
        $payload = $this->read($id);

        if (!array_key_exists($name, $payload)) {
            return true;
        }

        unset($payload[$name]);

        return $this->write($id, $payload);
    }

    /**
     * 检查键是否存在（值为 null 也算存在）
     *
     * @param string $id   Session ID
     * @param string $name 键名
     */
    #[\Override]
    public function has(string $id, string $name): bool
    {
        $payload = $this->read($id);

        if (!array_key_exists($name, $payload)) {
            return false;
        }

        return !$this->isExpired($payload[$name]);
    }

    /**
     * 会话文件是否存在
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function exists(string $id): bool
    {
        return is_file($this->getFilePath($this->validateId($id)));
    }

    /**
     * 清空指定 session 的所有数据
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function clear(string $id): bool
    {
        $file = $this->getFilePath($this->validateId($id));
        unset($this->cache[$id]);

        if (is_file($file)) {
            return @unlink($file);
        }

        return true;
    }

    /**
     * 开启 session（获取文件锁）
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function open(string $id): bool
    {
        return $this->acquireLock($id);
    }

    /**
     * 关闭 session（释放文件锁）
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
        $result = $this->clear($id);
        $this->forceReleaseLock($id);

        return $result;
    }

    /**
     * 迁移数据到新 ID（默认实现 + 释放旧 ID 上的残留锁）
     *
     * 会话在 regenerate 后身份切换到 toId，原 fromId 的锁不应继续持有，
     * 否则会泄漏文件锁句柄直到进程结束。
     *
     * @param string $fromId 原 Session ID
     * @param string $toId   新 Session ID
     * @param bool   $delete 是否删除原数据
     */
    #[\Override]
    public function migrate(string $fromId, string $toId, bool $delete = true): bool
    {
        $result = parent::migrate($fromId, $toId, $delete);

        if (isset($this->handles[$fromId])) {
            $this->forceReleaseLock($fromId);
        }

        return $result;
    }

    /**
     * 垃圾回收：清理过期键、空会话文件与残留锁文件
     *
     * @param int $maxLifetime 最大生命周期（秒）
     * @return int 清理的过期条目数量
     */
    #[\Override]
    public function gc(int $maxLifetime): int
    {
        $count = 0;
        $files = glob($this->path . DIRECTORY_SEPARATOR . $this->prefix . '*.php') ?: [];
        $deadline = time() - max(0, $maxLifetime);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $id = $this->getIdFromFile($file);
            $payload = $this->decode((string) @file_get_contents($file));
            $removed = 0;

            foreach ($payload as $key => $value) {
                if ($this->isExpired($value)) {
                    unset($payload[$key]);
                    $removed++;
                }
            }

            $stale = $maxLifetime > 0 && (int) @filemtime($file) < $deadline;

            if ($payload === [] || $stale) {
                @unlink($file);
                unset($this->cache[$id]);
                $count += max($removed, 1);

                continue;
            }

            if ($removed > 0) {
                $this->write($id, $payload);
                $count += $removed;
            }
        }

        $this->gcLockFiles($maxLifetime);

        return $count;
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
        $result = [];

        foreach ($this->read($id) as $key => $value) {
            if ($this->isExpired($value)) {
                continue;
            }

            $result[$key] = $this->unwrap($value);
        }

        return $result;
    }

    /**
     * 获取文件锁（可重入）
     *
     * @param string   $id      Session ID
     * @param int|null $timeout 等待超时（秒）
     */
    #[\Override]
    public function acquireLock(string $id, ?int $timeout = null): bool
    {
        $id = $this->validateId($id);

        if (isset($this->handles[$id])) {
            $this->lockDepth[$id]++;

            return true;
        }

        $lockFile = $this->getLockFile($id);
        $handle = @fopen($lockFile, 'c+');

        if ($handle === false) {
            throw LockException::unavailable($lockFile);
        }

        $wait = $timeout ?? $this->lockTimeout;
        $start = microtime(true);

        while (true) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handles[$id] = $handle;
                $this->lockDepth[$id] = 1;
                unset($this->cache[$id]);

                return true;
            }

            if (microtime(true) - $start >= $wait) {
                fclose($handle);

                return false;
            }

            usleep(5000);
        }
    }

    /**
     * 释放文件锁（可重入）
     *
     * @param string $id Session ID
     */
    #[\Override]
    public function releaseLock(string $id): bool
    {
        if (!isset($this->handles[$id])) {
            return true;
        }

        if (--$this->lockDepth[$id] > 0) {
            return true;
        }

        return $this->forceReleaseLock($id);
    }

    /**
     * 强制释放锁（忽略重入计数）
     *
     * @param string $id Session ID
     */
    protected function forceReleaseLock(string $id): bool
    {
        if (!isset($this->handles[$id])) {
            return true;
        }

        $handle = $this->handles[$id];
        @flock($handle, LOCK_UN);
        @fclose($handle);

        unset($this->handles[$id], $this->lockDepth[$id], $this->cache[$id]);

        return true;
    }

    /**
     * 读取 session 负载
     *
     * @param string $id Session ID
     * @return array<string, mixed>
     */
    protected function read(string $id): array
    {
        $id = $this->validateId($id);

        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $file = $this->getFilePath($id);

        if (!is_file($file)) {
            return [];
        }

        $payload = $this->decode((string) @file_get_contents($file));

        if (isset($this->handles[$id])) {
            $this->cache[$id] = $payload;
        }

        return $payload;
    }

    /**
     * 原子写入 session 负载
     *
     * @param string               $id      Session ID
     * @param array<string, mixed> $payload 负载
     */
    protected function write(string $id, array $payload): bool
    {
        $id = $this->validateId($id);
        $file = $this->getFilePath($id);
        $held = isset($this->handles[$id]);

        if (!$held && !$this->acquireLock($id)) {
            return false;
        }

        try {
            $tmp = $file . '.' . getmypid() . '.tmp';

            if (@file_put_contents($tmp, $this->encode($payload), LOCK_EX) === false) {
                return false;
            }

            @chmod($tmp, $this->fileMode);

            if (!@rename($tmp, $file)) {
                @unlink($tmp);

                return false;
            }

            if (isset($this->handles[$id])) {
                $this->cache[$id] = $payload;
            }

            return true;
        } finally {
            if (!$held) {
                $this->releaseLock($id);
            }
        }
    }

    /**
     * 编码负载为文件内容
     *
     * @param array<string, mixed> $payload 负载
     */
    protected function encode(array $payload): string
    {
        return self::GUARD . "\n" . serialize($payload);
    }

    /**
     * 解码文件内容（兼容 2.x 旧格式）
     *
     * @param string $contents 文件内容
     * @return array<string, mixed>
     */
    protected function decode(string $contents): array
    {
        if ($contents === '') {
            return [];
        }

        if (str_starts_with($contents, self::GUARD)) {
            $raw = substr($contents, strlen(self::GUARD) + 1);
            $data = @unserialize($raw, ['allowed_classes' => true]);

            return is_array($data) ? $data : [];
        }

        if (str_starts_with($contents, self::LEGACY_GUARD)) {
            return $this->decodeLegacy($contents);
        }

        return [];
    }

    /**
     * 解析 2.x 旧格式（`<?php return [...];`）
     *
     * @param string $contents 文件内容
     * @return array<string, mixed>
     */
    protected function decodeLegacy(string $contents): array
    {
        $expression = trim(substr($contents, strlen('<?php return')));
        $expression = rtrim($expression, ";\n\r\t ");

        try {
            /** @var mixed $data */
            $data = @eval('return ' . $expression . ';');
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * 获取会话文件路径
     *
     * @param string $id Session ID
     */
    protected function getFilePath(string $id): string
    {
        return $this->path . DIRECTORY_SEPARATOR . $this->prefix . $id . '.php';
    }

    /**
     * 获取锁文件路径
     *
     * @param string $id Session ID
     */
    protected function getLockFile(string $id): string
    {
        return $this->lockPath . DIRECTORY_SEPARATOR . $this->prefix . $id . '.lock';
    }

    /**
     * 从文件名提取 session ID
     *
     * @param string $file 文件路径
     */
    protected function getIdFromFile(string $file): string
    {
        return substr(basename($file), strlen($this->prefix), -4);
    }

    /**
     * 清理残留锁文件
     *
     * @param int $maxLifetime 最大生命周期（秒）
     */
    protected function gcLockFiles(int $maxLifetime): void
    {
        if ($maxLifetime <= 0) {
            return;
        }

        $deadline = time() - $maxLifetime;
        $locks = glob($this->lockPath . DIRECTORY_SEPARATOR . $this->prefix . '*.lock') ?: [];

        foreach ($locks as $lock) {
            $id = substr(basename($lock), strlen($this->prefix), -5);

            if (isset($this->handles[$id])) {
                continue;
            }

            if ((int) @filemtime($lock) < $deadline) {
                @unlink($lock);
            }
        }
    }

    /**
     * 确保目录存在
     *
     * @param string $dir 目录路径
     */
    protected function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, $this->dirMode, true) && !is_dir($dir)) {
            throw LockException::unavailable($dir);
        }
    }
}
