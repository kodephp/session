<?php

declare(strict_types=1);

namespace Kode\Session\Support;

use Kode\Session\Contract\Driver;
use Kode\Session\Session;
use Kode\Session\SessionManager;

/**
 * 并行 Session 管理 - 支持多进程/多线程并发访问
 * 提供分布式锁和多进程支持
 *
 * @author kode
 */
class ParallelSession
{
    /**
     * Session 管理器
     */
    protected SessionManager $manager;

    /**
     * Session 配置
     */
    protected array $config;

    /**
     * 当前 session
     */
    protected ?Session $session = null;

    /**
     * 驱动实例
     */
    protected ?Driver $driver = null;

    /**
     * 锁超时时间
     */
    protected int $lockTimeout;

    /**
     * 构造函数
     *
     * @param SessionManager $manager Session 管理器
     * @param array         $config  配置参数
     */
    public function __construct(SessionManager $manager, array $config = [])
    {
        $this->manager = $manager;
        $this->config = $config;
        $this->lockTimeout = $config['lock_timeout'] ?? 10;
    }

    /**
     * 创建并行 session
     *
     * @param string|null $id Session ID
     * @return Session
     */
    public function create(string $id = null): Session
    {
        $sessionId = $id ?? $this->manager->createId();
        $this->session = $this->manager->make($sessionId, $this->config);
        $this->session->start();

        $driverName = $this->config['driver'] ?? 'file';
        $this->driver = $this->manager->getDriver($driverName, $this->config);

        return $this->session;
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
     * 执行带锁的操作
     *
     * @param callable $callback 回调函数
     * @param int|null $timeout 超时时间
     * @return mixed
     * @throws \RuntimeException
     */
    public function withLock(callable $callback, int $timeout = null): mixed
    {
        if ($this->session === null) {
            throw new \RuntimeException('Session 未创建');
        }

        $sessionId = $this->session->getId();
        $lockTimeout = $timeout ?? $this->lockTimeout;

        $start = time();

        while (true) {
            if ($this->acquireLock($sessionId)) {
                try {
                    return $callback($this->session);
                } finally {
                    $this->releaseLock($sessionId);
                }
            }

            if (time() - $start >= $lockTimeout) {
                throw new \RuntimeException('获取锁超时');
            }

            usleep(10000);
        }
    }

    /**
     * 获取分布式锁
     *
     * @param string $sessionId Session ID
     * @return bool
     */
    protected function acquireLock(string $sessionId): bool
    {
        if ($this->driver === null) {
            return false;
        }

        return $this->driver->acquireLock($sessionId);
    }

    /**
     * 释放分布式锁
     *
     * @param string $sessionId Session ID
     * @return bool
     */
    protected function releaseLock(string $sessionId): bool
    {
        if ($this->driver === null) {
            return false;
        }

        return $this->driver->releaseLock($sessionId);
    }

    /**
     * 在子进程中执行
     *
     * @param callable $callback 回调函数
     * @param array    $data     传递给子进程的数据
     * @return mixed
     */
    public function fork(callable $callback, array $data = []): mixed
    {
        $sessionId = $this->session?->getId() ?? $this->manager->createId();
        $pipe = [];

        if (@socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pipe) === false) {
            return $this->forkWithStream($callback, $sessionId, $data);
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new \RuntimeException('无法创建子进程');
        }

        if ($pid === 0) {
            socket_close($pipe[0]);

            $session = $this->manager->make($sessionId, $this->config);
            $session->start();

            foreach ($data as $key => $value) {
                $session->set($key, $value);
            }

            $result = $callback($session);
            $resultData = serialize(['result' => $result, 'data' => $session->all()]);

            socket_write($pipe[1], $resultData);
            socket_close($pipe[1]);

            exit(0);
        }

        socket_close($pipe[1]);

        $resultData = '';
        while ($chunk = @socket_read($pipe[0], 65536)) {
            $resultData .= $chunk;
        }

        pcntl_wait($status);

        $result = unserialize($resultData);

        if ($this->session !== null) {
            foreach ($result['data'] ?? [] as $key => $value) {
                $this->session->set($key, $value);
            }
        }

        return $result['result'] ?? null;
    }

    /**
     * 使用流模拟 fork（兼容不支持的系统）
     *
     * @param callable $callback  回调函数
     * @param string  $sessionId Session ID
     * @param array   $data     数据
     * @return mixed
     */
    protected function forkWithStream(callable $callback, string $sessionId, array $data): mixed
    {
        return $callback($this->session);
    }

    /**
     * 在协程中执行
     *
     * @param callable $callback 回调函数
     * @param array    $data     数据
     * @return \Fiber
     */
    public function async(callable $callback, array $data = []): \Fiber
    {
        $sessionId = $this->session?->getId() ?? $this->manager->createId();
        $config = $this->config;
        $manager = $this->manager;

        return new \Fiber(function () use ($callback, $sessionId, $data, $config, $manager) {
            $session = $manager->make($sessionId, $config);
            $session->start();

            foreach ($data as $key => $value) {
                $session->set($key, $value);
            }

            FiberSessionStorage::set('session', $session);

            return $callback($session);
        });
    }
}
