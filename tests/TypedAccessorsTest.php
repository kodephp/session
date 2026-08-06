<?php

declare(strict_types=1);

namespace Kode\Session\Tests;

use Kode\Session\Driver\ArrayDriver;
use Kode\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * 强类型访问器测试：getInt / getFloat / getBool / getString / getArray。
 */
class TypedAccessorsTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $driver = new ArrayDriver([]);
        $this->session = new Session(bin2hex(random_bytes(16)), 'TEST', $driver);
        $this->session->start();
    }

    public function testGetInt(): void
    {
        $this->session->set('a', 5);
        $this->session->set('b', '10');
        $this->session->set('c', true);
        $this->session->set('d', 'not-a-number');

        $this->assertSame(5, $this->session->getInt('a'));
        $this->assertSame(10, $this->session->getInt('b'));
        $this->assertSame(1, $this->session->getInt('c'));
        $this->assertSame(0, $this->session->getInt('d'));
        $this->assertSame(7, $this->session->getInt('missing', 7));
    }

    public function testGetFloat(): void
    {
        $this->session->set('a', 3.14);
        $this->session->set('b', '2.5');
        $this->session->set('c', 9);

        $this->assertSame(3.14, $this->session->getFloat('a'));
        $this->assertSame(2.5, $this->session->getFloat('b'));
        $this->assertSame(9.0, $this->session->getFloat('c'));
        $this->assertSame(1.5, $this->session->getFloat('missing', 1.5));
    }

    public function testGetBool(): void
    {
        $this->session->set('a', true);
        $this->session->set('b', 1);
        $this->session->set('c', 'yes');
        $this->session->set('d', 'no');
        $this->session->set('e', 'random');

        $this->assertTrue($this->session->getBool('a'));
        $this->assertTrue($this->session->getBool('b'));
        $this->assertTrue($this->session->getBool('c'));
        $this->assertFalse($this->session->getBool('d'));
        $this->assertFalse($this->session->getBool('e'));
        $this->assertTrue($this->session->getBool('missing', true));
    }

    public function testGetString(): void
    {
        $this->session->set('a', 'hello');
        $this->session->set('b', 123);
        $this->session->set('c', false);
        $this->session->set('d', ['x']);

        $this->assertSame('hello', $this->session->getString('a'));
        $this->assertSame('123', $this->session->getString('b'));
        $this->assertSame('0', $this->session->getString('c'));
        $this->assertSame('', $this->session->getString('d'));
        $this->assertSame('def', $this->session->getString('missing', 'def'));
    }

    public function testGetArray(): void
    {
        $this->session->set('a', ['k' => 'v']);
        $this->session->set('b', 'not-array');

        $this->assertSame(['k' => 'v'], $this->session->getArray('a'));
        $this->assertSame([], $this->session->getArray('b'));
        $this->assertSame(['d' => 1], $this->session->getArray('missing', ['d' => 1]));
    }
}
