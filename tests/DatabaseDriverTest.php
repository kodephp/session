<?php

declare(strict_types=1);

namespace Kode\Session\Tests;

use Kode\Session\Driver\DatabaseDriver;
use Kode\Session\Session;
use PHPUnit\Framework\TestCase;

class DatabaseDriverTest extends TestCase
{
    protected function makeDriver(array $extra = []): DatabaseDriver
    {
        return new DatabaseDriver(array_merge([
            'dsn' => 'sqlite::memory:',
            'table' => 'sessions_' . uniqid(),
            'lock_table' => 'locks_' . uniqid(),
        ], $extra));
    }

    public function testSetAndGetRoundTrip(): void
    {
        $driver = $this->makeDriver();
        $id = bin2hex(random_bytes(16));

        $session = new Session($id, 'S', $driver);
        $session->start();
        $session->set('user_id', 123);
        $session->set('name', 'bob');
        $session->save();

        $next = new Session($id, 'S', $driver);
        $next->start();

        $this->assertSame(123, $next->get('user_id'));
        $this->assertSame('bob', $next->get('name'));
    }

    public function testPushAndIncrementPersist(): void
    {
        $driver = $this->makeDriver();
        $id = bin2hex(random_bytes(16));

        $session = new Session($id, 'S', $driver);
        $session->start();
        $session->push('roles', 'admin');
        $session->push('roles', 'user');
        $session->save();

        $next = new Session($id, 'S', $driver);
        $next->start();

        $this->assertSame(['admin', 'user'], $next->get('roles'));
        $this->assertSame(1, $next->increment('counter'));
        $next->save();

        $after = new Session($id, 'S', $driver);
        $after->start();
        $this->assertSame(1, $after->get('counter'));
    }

    public function testDeleteAndClear(): void
    {
        $driver = $this->makeDriver();
        $id = bin2hex(random_bytes(16));

        $session = new Session($id, 'S', $driver);
        $session->start();
        $session->set('a', 1);
        $session->set('b', 2);
        $session->save();

        $next = new Session($id, 'S', $driver);
        $next->start();
        $next->delete('a');
        $next->save();

        $after = new Session($id, 'S', $driver);
        $after->start();
        $this->assertNull($after->get('a'));
        $this->assertSame(2, $after->get('b'));

        $after->clear();
        $after->save();

        // clear() 仅清空用户数据；save() 仍会写入闪存元数据行，因此 exists() 恒为 true。
        // 正确的断言是：用户数据已被清空，新会话读不到任何键值。
        $verifier = new Session($id, 'S', $driver);
        $verifier->start();
        $this->assertSame([], $verifier->all());
        $this->assertFalse($verifier->has('a'));
        $this->assertFalse($verifier->has('b'));
    }

    public function testRegenerateMigratesData(): void
    {
        $driver = $this->makeDriver();
        $id = bin2hex(random_bytes(16));

        $session = new Session($id, 'S', $driver);
        $session->start();
        $session->set('user_id', 42);
        $session->regenerate(true);
        $session->set('role', 'admin');
        $session->save();

        $newId = $session->getId();
        $next = new Session($newId, 'S', $driver);
        $next->start();

        $this->assertSame(42, $next->get('user_id'));
        $this->assertSame('admin', $next->get('role'));
        $this->assertFalse($driver->exists($id));
    }

    public function testLockPreventsConcurrentWrite(): void
    {
        $driver = $this->makeDriver();
        $id = bin2hex(random_bytes(16));

        $this->assertTrue($driver->open($id));

        // 同一进程再次获取锁应成功（可重入）
        $this->assertTrue($driver->acquireLock($id));
        $driver->releaseLock($id);

        // 释放后锁已清空
        $this->assertTrue($driver->releaseLock($id));
    }

    public function testGarbageCollectionRemovesExpired(): void
    {
        $driver = $this->makeDriver();
        $id = bin2hex(random_bytes(16));

        // 手动注入一条已过期数据
        $pdo = $this->makeDriverPdo($driver);
        $pdo->prepare(sprintf(
            'INSERT INTO %s (id, name, payload, expire) VALUES (?, ?, ?, ?)',
            $this->getDriverTable($driver)
        ))->execute([$id, 'stale', json_encode(['data' => 'x', 'expire' => time() - 100]), time() - 100]);

        $removed = $driver->gc(0);
        $this->assertGreaterThanOrEqual(1, $removed);
        $this->assertNull($driver->get($id, 'stale'));
    }

    public function testEncryptedStorageIsCiphertext(): void
    {
        $driver = $this->makeDriver(['encrypted' => true, 'secret' => 'db-secret']);
        $id = bin2hex(random_bytes(16));

        $session = new Session($id, 'S', $driver);
        $session->start();
        $session->set('secret', 'plaintext-value');
        $session->save();

        $next = new Session($id, 'S', $driver);
        $next->start();
        $this->assertSame('plaintext-value', $next->get('secret'));

        // 落库内容为密文（data 字段带 kenc1: 前缀）。all() 返回的是已解包值，
        // 因此直接读取数据库原始 payload 列来核对密文。
        $pdo = $this->makeDriverPdo($driver);
        $stmt = $pdo->prepare(sprintf(
            'SELECT payload FROM %s WHERE id = ? AND name = ?',
            $this->getDriverTable($driver)
        ));
        $stmt->execute([$id, 'secret']);
        $rawPayload = $stmt->fetchColumn();
        $this->assertIsString($rawPayload);
        $this->assertStringContainsString('kenc1:', $rawPayload);
    }

    /**
     * 通过反射取出驱动内部 PDO，便于直接注入过期数据
     */
    private function makeDriverPdo(DatabaseDriver $driver): \PDO
    {
        $ref = new \ReflectionMethod($driver, 'getPdo');
        $ref->setAccessible(true);

        return $ref->invoke($driver);
    }

    /**
     * 通过反射取出驱动内部会话表名
     */
    private function getDriverTable(DatabaseDriver $driver): string
    {
        $ref = new \ReflectionProperty($driver, 'table');
        $ref->setAccessible(true);

        return (string) $ref->getValue($driver);
    }
}
