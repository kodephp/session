<?php

declare(strict_types=1);

namespace Kode\Session\Driver;

use RuntimeException;

/**
 * 文件驱动 - 基于本地文件系统存储 session
 * 适合单机部署或开发环境使用
 *
 * @author kode
 */
class FileDriver extends AbstractDriver
{
    /**
     * 文件锁目录
     */
    protected string $lockPath;

    /**
     * 构造函数
     *
     * @param array $config 配置数组
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->lockPath = ($config['lock_path'] ?? $this->path) . '/locks';

        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }

        if (!is_dir($this->lockPath)) {
            mkdir($this->lockPath, 0755, true);
        }
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
        $data = $this->read($id);

        if (!isset($data[$name])) {
            return $default;
        }

        $value = $data[$name];

        if ($this->isExpired($value)) {
            $this->delete($id, $name);
            return $default;
        }

        return $value['data'] ?? $default;
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
        $data = $this->read($id);
        $data[$name] = [
            'data' => $value,
            'expire' => $lifetime > 0 ? time() + $lifetime : 0,
        ];

        return $this->write($id, $data);
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
        $data = $this->read($id);

        if (!isset($data[$name])) {
            return true;
        }

        unset($data[$name]);
        return $this->write($id, $data);
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
        $value = $this->get($id, $name, null);
        return $value !== null;
    }

    /**
     * 清空指定 session 的所有数据
     *
     * @param string $id Session ID
     * @return bool
     */
    public function clear(string $id): bool
    {
        $file = $this->getFilePath($id);

        if (file_exists($file)) {
            return unlink($file);
        }

        return true;
    }

    /**
     * 开启 session（获取文件锁）
     *
     * @param string $id Session ID
     * @return bool
     */
    public function open(string $id): bool
    {
        return $this->acquireLock($id);
    }

    /**
     * 关闭 session（释放文件锁）
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
        $count = 0;
        $files = glob($this->path . '/' . $this->prefix . '*.php');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                $data = $this->unserialize($file);

                foreach ($data as $key => $value) {
                    if ($this->isExpired($value)) {
                        unset($data[$key]);
                        $count++;
                    }
                }

                if (count($data) === 0) {
                    unlink($file);
                } elseif ($count > 0) {
                    $this->write($this->getIdFromFile($file), $data);
                }
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
        return $this->read($id);
    }

    /**
     * 读取 session 数据
     *
     * @param string $id Session ID
     * @return array
     */
    protected function read(string $id): array
    {
        $file = $this->getFilePath($id);

        if (!file_exists($file)) {
            return [];
        }

        return $this->unserialize($file);
    }

    /**
     * 写入 session 数据
     *
     * @param string $id   Session ID
     * @param array  $data 数据
     * @return bool
     * @throws RuntimeException
     */
    protected function write(string $id, array $data): bool
    {
        $file = $this->getFilePath($id);
        $lockFile = $this->getLockFile($id);

        $fp = fopen($lockFile, 'c+');

        if (!$fp) {
            throw new RuntimeException("无法创建锁文件: {$lockFile}");
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException("无法获取文件锁: {$lockFile}");
        }

        try {
            $result = file_put_contents($file, $this->serialize($data), LOCK_EX);
            return $result !== false;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * 获取锁文件路径
     *
     * @param string $id Session ID
     * @return string
     */
    protected function getLockFile(string $id): string
    {
        return $this->lockPath . '/' . $this->prefix . $id . '.lock';
    }

    /**
     * 获取文件锁
     *
     * @param string $id     Session ID
     * @param int    $timeout 超时时间（秒）
     * @return bool
     */
    protected function acquireLock(string $id, int $timeout = 10): bool
    {
        $lockFile = $this->getLockFile($id);
        $fp = fopen($lockFile, 'c+');

        if (!$fp) {
            return false;
        }

        $start = time();

        while (true) {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                return true;
            }

            if (time() - $start >= $timeout) {
                fclose($fp);
                return false;
            }

            usleep(10000);
        }
    }

    /**
     * 释放文件锁
     *
     * @param string $id Session ID
     * @return bool
     */
    protected function releaseLock(string $id): bool
    {
        $lockFile = $this->getLockFile($id);

        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        return true;
    }

    /**
     * 从文件名提取 session ID
     *
     * @param string $file 文件路径
     * @return string
     */
    protected function getIdFromFile(string $file): string
    {
        $basename = basename($file);
        $len = strlen($this->prefix);
        $name = substr($basename, $len, -4);
        return $name;
    }

    /**
     * 检查数据是否过期
     *
     * @param array $value 数据值
     * @return bool
     */
    protected function isExpired(array $value): bool
    {
        if (!isset($value['expire']) || $value['expire'] === 0) {
            return false;
        }

        return time() > $value['expire'];
    }
}
