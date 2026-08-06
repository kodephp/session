<?php

declare(strict_types=1);

namespace Kode\Session\Tests;

use Kode\Session\SessionManager;
use PHPUnit\Framework\TestCase;

class SessionManagerTest extends TestCase
{
    protected string $tempPath;
    protected SessionManager $manager;

    protected function setUp(): void
    {
        $this->tempPath = sys_get_temp_dir() . '/kode_session_test_' . uniqid();

        $this->manager = new SessionManager([
            'default' => 'file',
            'drivers' => [
                'file' => [
                    'path' => $this->tempPath,
                    'prefix' => 'test_',
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempPath);
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function testCreate(): void
    {
        $manager = SessionManager::create([
            'default' => 'file',
        ]);

        $this->assertInstanceOf(SessionManager::class, $manager);
    }

    public function testMakeSession(): void
    {
        $sessionId = bin2hex(random_bytes(16));
        $session = $this->manager->make($sessionId);

        $this->assertEquals($sessionId, $session->getId());
    }

    public function testGetDriver(): void
    {
        $driver = $this->manager->getDriver('file');

        $this->assertInstanceOf(\Kode\Session\Contract\Driver::class, $driver);
    }

    public function testCreateId(): void
    {
        $id1 = $this->manager->createId();
        $id2 = $this->manager->createId();

        $this->assertNotEquals($id1, $id2);
        $this->assertEquals(32, strlen($id1));
    }

    public function testGetConfig(): void
    {
        $config = $this->manager->getConfig();

        $this->assertArrayHasKey('default', $config);
        $this->assertEquals('file', $config['default']);
    }

    public function testGetConfigWithKey(): void
    {
        $default = $this->manager->getConfig('default');

        $this->assertEquals('file', $default);
    }

    public function testGetConfigWithDefault(): void
    {
        $value = $this->manager->getConfig('nonexistent', 'default_value');

        $this->assertEquals('default_value', $value);
    }

    public function testSetConfig(): void
    {
        $this->manager->setConfig('test_key', 'test_value');

        $this->assertEquals('test_value', $this->manager->getConfig('test_key'));
    }

    public function testHasSession(): void
    {
        $this->assertFalse($this->manager->hasSession());

        $session = $this->manager->make($this->manager->createId());
        $this->manager->setSession($session);

        $this->assertTrue($this->manager->hasSession());
    }

    public function testClearCache(): void
    {
        $driver1 = $this->manager->getDriver('file');
        $driver2 = $this->manager->getDriver('file');

        $this->assertSame($driver1, $driver2);

        $this->manager->clearCache();

        $driver3 = $this->manager->getDriver('file');
        $this->assertNotSame($driver1, $driver3);
    }

    public function testArrayDriver(): void
    {
        $manager = new SessionManager([
            'default' => 'array',
            'drivers' => ['array' => []],
        ]);

        $session = $manager->make($manager->createId());
        $session->start();
        $session->set('foo', 'bar');

        $this->assertEquals('bar', $session->get('foo'));
    }

    public function testExtendCustomDriver(): void
    {
        $manager = new SessionManager([
            'default' => 'file',
            'drivers' => ['file' => ['path' => $this->tempPath, 'prefix' => 'test_']],
        ]);

        $manager->extend('memory', function (array $config) {
            return new \Kode\Session\Driver\ArrayDriver($config);
        });

        $driver = $manager->getDriver('memory');

        $this->assertInstanceOf(\Kode\Session\Driver\ArrayDriver::class, $driver);

        $session = $manager->make($manager->createId(), ['driver' => 'memory']);
        $session->start();
        $session->set('x', 1);

        $this->assertEquals(1, $session->get('x'));
    }

    public function testFromRequestValidatesId(): void
    {
        $_COOKIE['KODE_SESSION'] = '../../etc/passwd';

        $manager = new SessionManager([
            'default' => 'file',
            'drivers' => ['file' => ['path' => $this->tempPath, 'prefix' => 'test_']],
        ]);

        $session = $manager->fromRequest();

        // 非法 ID 应被替换为合法生成值，而非原字符串
        $this->assertNotEquals('../../etc/passwd', $session->getId());

        unset($_COOKIE['KODE_SESSION']);
    }

    public function testGcDelegatesToDriver(): void
    {
        $manager = new SessionManager([
            'default' => 'database',
            'drivers' => [
                'database' => [
                    'dsn' => 'sqlite::memory:',
                    'table' => 'gc_sessions',
                    'lock_table' => 'gc_locks',
                ],
            ],
        ]);

        $driver = $manager->getDriver('database');
        $id = bin2hex(random_bytes(16));

        // 一条有效数据
        $driver->set($id, 'fresh', 'keep-me');

        // 直接注入一条已过期数据（绕过驱动，模拟历史脏数据）
        $pdo = (new \ReflectionMethod($driver, 'getPdo'))->invoke($driver);
        $pdo->prepare('INSERT INTO gc_sessions (id, name, payload, expire) VALUES (?, ?, ?, ?)')
            ->execute([$id, 'stale', json_encode(['data' => 'x', 'expire' => time() - 100]), time() - 100]);

        $removed = $manager->gc(0);

        $this->assertGreaterThanOrEqual(1, $removed);
        $this->assertFalse($driver->has($id, 'stale'));
        $this->assertTrue($driver->has($id, 'fresh'));
    }
}
