<?php

declare(strict_types=1);

namespace Kode\Session\Tests;

use Kode\Session\Driver\FileDriver;
use PHPUnit\Framework\TestCase;

class FileDriverTest extends TestCase
{
    protected string $tempPath;
    protected FileDriver $driver;
    protected string $sessionId;

    protected function setUp(): void
    {
        $this->tempPath = sys_get_temp_dir() . '/kode_session_test_' . uniqid();
        mkdir($this->tempPath, 0755, true);

        $this->driver = new FileDriver([
            'path' => $this->tempPath,
            'prefix' => 'test_',
        ]);

        $this->sessionId = bin2hex(random_bytes(16));
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

    public function testSetAndGet(): void
    {
        $this->driver->set($this->sessionId, 'name', 'value');

        $this->assertEquals('value', $this->driver->get($this->sessionId, 'name'));
    }

    public function testGetDefaultValue(): void
    {
        $value = $this->driver->get($this->sessionId, 'nonexistent', 'default');

        $this->assertEquals('default', $value);
    }

    public function testHas(): void
    {
        $this->driver->set($this->sessionId, 'name', 'value');

        $this->assertTrue($this->driver->has($this->sessionId, 'name'));
        $this->assertFalse($this->driver->has($this->sessionId, 'nonexistent'));
    }

    public function testDelete(): void
    {
        $this->driver->set($this->sessionId, 'name', 'value');
        $this->assertTrue($this->driver->has($this->sessionId, 'name'));

        $this->driver->delete($this->sessionId, 'name');
        $this->assertFalse($this->driver->has($this->sessionId, 'name'));
    }

    public function testClear(): void
    {
        $this->driver->set($this->sessionId, 'name1', 'value1');
        $this->driver->set($this->sessionId, 'name2', 'value2');

        $this->assertTrue($this->driver->has($this->sessionId, 'name1'));
        $this->assertTrue($this->driver->has($this->sessionId, 'name2'));

        $this->driver->clear($this->sessionId);

        $this->assertFalse($this->driver->has($this->sessionId, 'name1'));
        $this->assertFalse($this->driver->has($this->sessionId, 'name2'));
    }

    public function testPull(): void
    {
        $this->driver->set($this->sessionId, 'name', 'value');

        $value = $this->driver->pull($this->sessionId, 'name');

        $this->assertEquals('value', $value);
        $this->assertFalse($this->driver->has($this->sessionId, 'name'));
    }

    public function testRemember(): void
    {
        $called = false;

        $value = $this->driver->remember(
            $this->sessionId,
            'name',
            function () use (&$called) {
                $called = true;
                return 'computed';
            }
        );

        $this->assertTrue($called);
        $this->assertEquals('computed', $value);

        $called = false;
        $value = $this->driver->remember(
            $this->sessionId,
            'name',
            function () use (&$called) {
                $called = true;
                return 'computed_again';
            }
        );

        $this->assertFalse($called);
        $this->assertEquals('computed', $value);
    }

    public function testOpenAndClose(): void
    {
        $this->assertTrue($this->driver->open($this->sessionId));
        $this->assertTrue($this->driver->close($this->sessionId));
    }

    public function testDestroy(): void
    {
        $this->driver->set($this->sessionId, 'name', 'value');
        $this->assertTrue($this->driver->has($this->sessionId, 'name'));

        $this->driver->destroy($this->sessionId);

        $this->assertFalse($this->driver->has($this->sessionId, 'name'));
    }

    public function testGenerateId(): void
    {
        $id1 = $this->driver->generateId();
        $id2 = $this->driver->generateId();

        $this->assertNotEquals($id1, $id2);
        $this->assertEquals(32, strlen($id1));
    }

    public function testAll(): void
    {
        $this->driver->set($this->sessionId, 'name1', 'value1');
        $this->driver->set($this->sessionId, 'name2', 'value2');

        $all = $this->driver->all($this->sessionId);

        $this->assertArrayHasKey('name1', $all);
        $this->assertArrayHasKey('name2', $all);
        $this->assertEquals('value1', $all['name1']['data'] ?? null);
    }

    public function testSetWithLifetime(): void
    {
        $this->driver->set($this->sessionId, 'name', 'value', 1);

        $this->assertTrue($this->driver->has($this->sessionId, 'name'));

        sleep(2);

        $this->assertFalse($this->driver->has($this->sessionId, 'name'));
    }

    public function testMultipleSessions(): void
    {
        $id1 = bin2hex(random_bytes(16));
        $id2 = bin2hex(random_bytes(16));

        $this->driver->set($id1, 'name', 'value1');
        $this->driver->set($id2, 'name', 'value2');

        $this->assertEquals('value1', $this->driver->get($id1, 'name'));
        $this->assertEquals('value2', $this->driver->get($id2, 'name'));
    }
}
