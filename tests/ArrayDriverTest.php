<?php

declare(strict_types=1);

namespace Kode\Session\Tests;

use Kode\Session\Driver\ArrayDriver;
use PHPUnit\Framework\TestCase;

class ArrayDriverTest extends TestCase
{
    protected ArrayDriver $driver;
    protected string $sessionId;

    protected function setUp(): void
    {
        $this->driver = new ArrayDriver(['prefix' => 't_']);
        $this->sessionId = bin2hex(random_bytes(16));
    }

    public function testSetAndGet(): void
    {
        $this->driver->set($this->sessionId, 'name', 'value');

        $this->assertEquals('value', $this->driver->get($this->sessionId, 'name'));
    }

    public function testHasAndDelete(): void
    {
        $this->driver->set($this->sessionId, 'name', 'value');
        $this->assertTrue($this->driver->has($this->sessionId, 'name'));

        $this->driver->delete($this->sessionId, 'name');
        $this->assertFalse($this->driver->has($this->sessionId, 'name'));
    }

    public function testExistsAndClear(): void
    {
        $this->driver->set($this->sessionId, 'a', 1);
        $this->assertTrue($this->driver->exists($this->sessionId));

        $this->driver->clear($this->sessionId);
        $this->assertFalse($this->driver->exists($this->sessionId));
    }

    public function testAllReturnsUnwrapped(): void
    {
        $this->driver->set($this->sessionId, 'a', 1);
        $this->driver->set($this->sessionId, 'b', 2);

        $this->assertEquals(['a' => 1, 'b' => 2], $this->driver->all($this->sessionId));
    }

    public function testMigrate(): void
    {
        $this->driver->set($this->sessionId, 'a', 1);

        $newId = bin2hex(random_bytes(16));
        $this->driver->migrate($this->sessionId, $newId, true);

        $this->assertEquals(1, $this->driver->get($newId, 'a'));
        $this->assertFalse($this->driver->exists($this->sessionId));
    }
}
