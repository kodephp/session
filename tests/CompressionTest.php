<?php

declare(strict_types=1);

namespace Kode\Session\Tests;

use Kode\Session\Driver\ArrayDriver;
use Kode\Session\Session;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 透明压缩（compress）测试：覆盖往返、密文前缀、体积对比、与加密叠加。
 *
 * 使用 ArrayDriver（内存）可直接读取底层存储结构中 data 字段的原始值，
 * 便于断言「落库内容确为压缩载荷」。
 */
class CompressionTest extends TestCase
{
    private function rawStore(ArrayDriver $driver): array
    {
        $prop = new ReflectionProperty(ArrayDriver::class, 'store');
        $prop->setAccessible(true);

        return $prop->getValue($driver);
    }

    public function testRoundTripWithCompression(): void
    {
        $driver = new ArrayDriver(['compress' => true]);
        $session = new Session(bin2hex(random_bytes(16)), 'TEST', $driver);
        $session->start();

        $session->set('user', ['id' => 1, 'name' => 'kode', 'roles' => ['admin', 'user']]);
        $session->set('count', 42);
        $session->save();

        $reloaded = new Session($session->getId(), 'TEST', $driver);
        $reloaded->start();

        $this->assertSame(
            ['id' => 1, 'name' => 'kode', 'roles' => ['admin', 'user']],
            $reloaded->get('user')
        );
        $this->assertSame(42, $reloaded->get('count'));
    }

    public function testStoredPayloadIsCompressed(): void
    {
        $driver = new ArrayDriver(['compress' => true]);
        $session = new Session(bin2hex(random_bytes(16)), 'TEST', $driver);
        $session->start();

        $session->set('big', str_repeat('hello world ', 200));
        $session->save();

        $store = $this->rawStore($driver);
        $entry = $store[$session->getId()]['big'] ?? null;

        $this->assertNotNull($entry);
        $this->assertIsString($entry['data']);
        $this->assertStringStartsWith('kz1:', $entry['data']);
    }

    public function testCompressedSmallerThanUncompressed(): void
    {
        $payload = str_repeat('session-heavy-data-', 500);

        $compressed = new ArrayDriver(['compress' => true]);
        $plain = new ArrayDriver([]);

        $sC = new Session(bin2hex(random_bytes(16)), 'T', $compressed);
        $sC->start();
        $sC->set('k', $payload);
        $sC->save();

        $sP = new Session(bin2hex(random_bytes(16)), 'T', $plain);
        $sP->start();
        $sP->set('k', $payload);
        $sP->save();

        $cData = $this->rawStore($compressed)[$sC->getId()]['k']['data'];
        $pData = $this->rawStore($plain)[$sP->getId()]['k']['data'];

        $this->assertLessThan(strlen((string) $pData), strlen((string) $cData));
    }

    public function testCompressWithEncrypt(): void
    {
        $driver = new ArrayDriver([
            'compress'  => true,
            'encrypted' => true,
            'secret'    => 'top-secret',
        ]);
        $session = new Session(bin2hex(random_bytes(16)), 'TEST', $driver);
        $session->start();

        $session->set('secret', ['token' => 'abc', 'exp' => 123]);
        $session->save();

        $store = $this->rawStore($driver);
        $entry = $store[$session->getId()]['secret'];

        // 处理顺序为「先压缩后加密」，故外层是密文前缀
        $this->assertStringStartsWith('kenc1:', $entry['data']);

        $reloaded = new Session($session->getId(), 'TEST', $driver);
        $reloaded->start();

        $this->assertSame(['token' => 'abc', 'exp' => 123], $reloaded->get('secret'));
    }

    public function testNoCompressNoPrefix(): void
    {
        $driver = new ArrayDriver([]);
        $session = new Session(bin2hex(random_bytes(16)), 'TEST', $driver);
        $session->start();

        $session->set('k', 'value');
        $session->save();

        $data = $this->rawStore($driver)[$session->getId()]['k']['data'];

        $this->assertIsString($data);
        $this->assertStringStartsNotWith('kz1:', $data);
    }
}
