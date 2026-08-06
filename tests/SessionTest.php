<?php

declare(strict_types=1);

namespace Kode\Session\Tests;

use Kode\Session\Driver\FileDriver;
use Kode\Session\Session;
use PHPUnit\Framework\TestCase;

class SessionTest extends TestCase
{
    protected string $tempPath;
    protected FileDriver $driver;
    protected Session $session;
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
        $this->session = new Session($this->sessionId, 'TEST_SESSION', $this->driver);
    }

    protected function tearDown(): void
    {
        if ($this->session->isStarted()) {
            $this->session->destroy();
        }

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

    public function testGetId(): void
    {
        $this->assertEquals($this->sessionId, $this->session->getId());
    }

    public function testGetName(): void
    {
        $this->assertEquals('TEST_SESSION', $this->session->getName());
    }

    public function testIsStarted(): void
    {
        $this->assertFalse($this->session->isStarted());

        $this->session->start();
        $this->assertTrue($this->session->isStarted());
    }

    public function testStartAndClose(): void
    {
        $this->assertTrue($this->session->start());
        $this->assertTrue($this->session->isStarted());

        $this->session->close();
        $this->assertFalse($this->session->isStarted());
    }

    public function testSetAndGet(): void
    {
        $this->session->start();

        $this->session->set('name', 'value');
        $this->assertEquals('value', $this->session->get('name'));

        $this->session->set('array', ['key' => 'val']);
        $this->assertEquals(['key' => 'val'], $this->session->get('array'));
    }

    public function testGetDefaultValue(): void
    {
        $this->session->start();

        $this->assertEquals('default', $this->session->get('nonexistent', 'default'));
    }

    public function testHas(): void
    {
        $this->session->start();

        $this->session->set('name', 'value');

        $this->assertTrue($this->session->has('name'));
        $this->assertFalse($this->session->has('nonexistent'));
    }

    public function testDelete(): void
    {
        $this->session->start();

        $this->session->set('name', 'value');
        $this->assertTrue($this->session->has('name'));

        $this->session->delete('name');
        $this->assertFalse($this->session->has('name'));
    }

    public function testClear(): void
    {
        $this->session->start();

        $this->session->set('name1', 'value1');
        $this->session->set('name2', 'value2');

        $this->session->clear();

        $this->assertCount(0, $this->session);
    }

    public function testPull(): void
    {
        $this->session->start();

        $this->session->set('name', 'value');

        $value = $this->session->pull('name');

        $this->assertEquals('value', $value);
        $this->assertFalse($this->session->has('name'));
    }

    public function testRemember(): void
    {
        $this->session->start();

        $called = false;

        $value = $this->session->remember('name', function () use (&$called) {
            $called = true;
            return 'computed';
        });

        $this->assertTrue($called);
        $this->assertEquals('computed', $value);

        $called = false;
        $value = $this->session->remember('name', function () use (&$called) {
            $called = true;
            return 'computed_again';
        });

        $this->assertFalse($called);
    }

    public function testFlash(): void
    {
        $this->session->start();

        $this->session->flash('name', 'value');
        $this->assertEquals('value', $this->session->get('name'));
        $this->assertTrue($this->session->has('name'));

        $this->session->ageFlash();
        $this->assertEquals('value', $this->session->get('name'));
        $this->assertTrue($this->session->has('name'));

        $this->session->flushFlash();
        $this->assertFalse($this->session->has('name'));
    }

    public function testRegenerate(): void
    {
        $this->session->start();

        $oldId = $this->session->getId();

        $this->session->regenerate();

        $this->assertNotEquals($oldId, $this->session->getId());
    }

    public function testDestroy(): void
    {
        $this->session->start();

        $this->session->set('name', 'value');

        $this->session->destroy();

        $this->assertFalse($this->session->has('name'));
    }

    public function testCsrfToken(): void
    {
        $this->session->start();

        $token1 = $this->session->token();
        $this->assertNotEmpty($token1);

        $token2 = $this->session->token();
        $this->assertEquals($token1, $token2);
    }

    public function testVerifyCsrfToken(): void
    {
        $this->session->start();

        $token = $this->session->token();

        $this->assertTrue($this->session->verifyCsrfToken($token));
        $this->assertFalse($this->session->verifyCsrfToken('invalid_token'));
    }

    public function testErrors(): void
    {
        $this->session->start();

        $this->session->setError('email', 'Invalid email');

        $this->assertTrue($this->session->hasError('email'));
        $this->assertFalse($this->session->hasError('name'));
        $this->assertEquals('Invalid email', $this->session->getError('email'));
    }

    public function testSuccesses(): void
    {
        $this->session->start();

        $this->session->setSuccess('saved', 'Data saved successfully');

        $this->assertTrue($this->session->hasSuccess('saved'));
        $this->assertEquals('Data saved successfully', $this->session->getSuccess('saved'));
    }

    public function testArrayAccess(): void
    {
        $this->session->start();

        $this->session['name'] = 'value';

        $this->assertTrue(isset($this->session['name']));
        $this->assertEquals('value', $this->session['name']);

        unset($this->session['name']);

        $this->assertFalse(isset($this->session['name']));
    }

    public function testCount(): void
    {
        $this->session->start();

        $this->session->set('name1', 'value1');
        $this->session->set('name2', 'value2');

        $this->assertCount(2, $this->session);
    }

    public function testIterator(): void
    {
        $this->session->start();

        $this->session->set('name1', 'value1');
        $this->session->set('name2', 'value2');

        $data = iterator_to_array($this->session->getIterator());

        $this->assertCount(2, $data);
        $this->assertArrayHasKey('name1', $data);
        $this->assertArrayHasKey('name2', $data);
    }

    public function testRegenerateMigratesData(): void
    {
        $this->session->start();
        $this->session->set('user_id', 42);
        $this->session->set('name', 'alice');

        $oldId = $this->session->getId();
        $this->session->regenerate(true);

        $newId = $this->session->getId();
        $this->assertNotEquals($oldId, $newId);

        // 数据应迁移到新 ID
        $this->session->set('role', 'admin');
        $this->session->save();

        $next = new Session($newId, 'TEST_SESSION', $this->driver);
        $next->start();

        $this->assertEquals(42, $next->get('user_id'));
        $this->assertEquals('alice', $next->get('name'));
        $this->assertEquals('admin', $next->get('role'));

        // 旧 ID 已被销毁
        $this->assertFalse($this->driver->exists($oldId));
    }

    public function testFlashPersistsAcrossRequests(): void
    {
        $this->session->start();
        $this->session->flash('message', 'saved!');
        $this->session->save();

        $next = new Session($this->sessionId, 'TEST_SESSION', $this->driver);
        $next->start();

        // 下一轮请求可读取闪存
        $this->assertEquals('saved!', $next->get('message'));
        $next->save();

        // 再下一轮请求闪存应消失
        $after = new Session($this->sessionId, 'TEST_SESSION', $this->driver);
        $after->start();
        $this->assertNull($after->get('message'));
    }

    public function testFlashCanStoreNull(): void
    {
        $this->session->start();
        $this->session->flash('nullable', null);

        $this->assertTrue($this->session->has('nullable'));
        $this->assertNull($this->session->get('nullable'));
    }

    public function testNowAndOldFlash(): void
    {
        $this->session->start();
        $this->session->now('temp', 'value');

        // 当前请求可用
        $this->assertEquals('value', $this->session->get('temp'));
        $this->assertEquals('value', $this->session->old('temp'));
    }

    public function testKeepExtendsFlashLife(): void
    {
        $this->session->start();
        $this->session->flash('greeting', 'hello');
        $this->session->save();

        $next = new Session($this->sessionId, 'TEST_SESSION', $this->driver);
        $next->start();
        $this->assertEquals('hello', $next->get('greeting'));

        // keep 使其多存活一轮
        $next->keep(['greeting']);
        $next->save();

        $after = new Session($this->sessionId, 'TEST_SESSION', $this->driver);
        $after->start();
        $this->assertEquals('hello', $after->get('greeting'));
    }

    public function testIncrementAndDecrement(): void
    {
        $this->session->start();

        $this->assertEquals(1, $this->session->increment('counter'));
        $this->assertEquals(6, $this->session->increment('counter', 5));
        $this->assertEquals(4, $this->session->decrement('counter', 2));
    }

    public function testPush(): void
    {
        $this->session->start();

        $this->session->push('items', 'a');
        $this->session->push('items', 'b');

        $this->assertEquals(['a', 'b'], $this->session->get('items'));
    }

    public function testOnlyAndExcept(): void
    {
        $this->session->start();
        $this->session->set('a', 1);
        $this->session->set('b', 2);
        $this->session->set('c', 3);

        $this->assertEquals(['a' => 1, 'c' => 3], $this->session->only(['a', 'c']));
        $this->assertEquals(['b' => 2], $this->session->except(['a', 'c']));
    }

    public function testReplace(): void
    {
        $this->session->start();
        $this->session->set('old', 'value');
        $this->session->replace(['new' => 'fresh']);

        $this->assertFalse($this->session->has('old'));
        $this->assertEquals('fresh', $this->session->get('new'));
    }

    public function testForget(): void
    {
        $this->session->start();
        $this->session->set('x', 1);
        $this->session->set('y', 2);

        $this->session->forget(['x', 'y']);

        $this->assertFalse($this->session->has('x'));
        $this->assertFalse($this->session->has('y'));
    }

    public function testFlush(): void
    {
        $this->session->start();
        $this->session->set('a', 1);
        $this->session->set('b', 2);

        $this->session->flush();

        $this->assertCount(0, $this->session);
    }
}
