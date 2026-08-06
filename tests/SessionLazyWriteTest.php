<?php

declare(strict_types=1);

namespace Kode\Session\Tests;

use Kode\Session\Driver\ArrayDriver;
use Kode\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * 延迟写盘（lazy write-through）与脏标记（dirty / removed）行为测试
 *
 * 设计：set/delete 仅更新内存与脏标记，真正落盘推迟到 save()/close() 一次性批量完成。
 * 因此未 save 前，新会话读不到本请求的写入；isDirty()/hasChanges() 反映待落盘状态。
 */
class SessionLazyWriteTest extends TestCase
{
    private ArrayDriver $driver;

    protected function setUp(): void
    {
        // 共享同一驱动实例，确保跨 Session 对象的存储一致
        $this->driver = new ArrayDriver([]);
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function testSetIsNotPersistedUntilSave(): void
    {
        $id = $this->newId();

        $writer = new Session($id, 'S', $this->driver);
        $writer->start();
        $writer->set('token', 'abc123');
        $this->assertTrue($writer->isDirty());
        $this->assertTrue($writer->hasChanges());

        // 未 save，另一会话读不到
        $reader = new Session($id, 'S', $this->driver);
        $reader->start();
        $this->assertFalse($reader->has('token'));
        $this->assertNull($reader->get('token'));

        // save 后再读，应当命中
        $writer->save();
        $reader2 = new Session($id, 'S', $this->driver);
        $reader2->start();
        $this->assertSame('abc123', $reader2->get('token'));
        $this->assertFalse($reader2->isDirty());
    }

    public function testDeleteIsDeferredUntilSave(): void
    {
        $id = $this->newId();

        $seed = new Session($id, 'S', $this->driver);
        $seed->start();
        $seed->set('name', 'bob');
        $seed->save();

        $deleter = new Session($id, 'S', $this->driver);
        $deleter->start();
        $this->assertTrue($deleter->has('name'));
        $deleter->delete('name');
        $this->assertTrue($deleter->isDirty());
        $this->assertNull($deleter->get('name'));

        // 未 save，另一会话仍读得到
        $peek = new Session($id, 'S', $this->driver);
        $peek->start();
        $this->assertSame('bob', $peek->get('name'));

        $deleter->save();
        $after = new Session($id, 'S', $this->driver);
        $after->start();
        $this->assertFalse($after->has('name'));
    }

    public function testClearEmptiesDataAndMarksDirty(): void
    {
        $id = $this->newId();

        $seed = new Session($id, 'S', $this->driver);
        $seed->start();
        $seed->set('a', 1);
        $seed->set('b', 2);
        $seed->save();

        $clearer = new Session($id, 'S', $this->driver);
        $clearer->start();
        $this->assertSame(2, $clearer->count());

        $clearer->clear();
        $this->assertTrue($clearer->isDirty());
        $this->assertSame([], $clearer->raw());

        $clearer->save();

        $verifier = new Session($id, 'S', $this->driver);
        $verifier->start();
        $this->assertSame([], $verifier->all());
        $this->assertFalse($verifier->has('a'));
        $this->assertFalse($verifier->has('b'));
    }

    public function testSetOverDeleteReMarksDirtyNotRemoved(): void
    {
        $id = $this->newId();

        $seed = new Session($id, 'S', $this->driver);
        $seed->start();
        $seed->set('k', 'old');
        $seed->save();

        $s = new Session($id, 'S', $this->driver);
        $s->start();
        $s->delete('k');      // 移入 removed
        $s->set('k', 'new');  // 从 removed 移出，重新置为 dirty
        $this->assertTrue($s->isDirty());

        $s->save();

        $after = new Session($id, 'S', $this->driver);
        $after->start();
        $this->assertSame('new', $after->get('k'));
    }

    public function testClosePersistsPendingChanges(): void
    {
        $id = $this->newId();

        $s = new Session($id, 'S', $this->driver);
        $s->start();
        $s->set('lazy', 1);
        $s->close(); // 兜底落盘

        $after = new Session($id, 'S', $this->driver);
        $after->start();
        $this->assertSame(1, $after->get('lazy'));
    }
}
