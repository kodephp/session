<?php

declare(strict_types=1);

namespace Kode\Session\Tests;

use Kode\Session\Driver\ArrayDriver;
use Kode\Session\Driver\CookieDriver;
use Kode\Session\Driver\FileDriver;
use Kode\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * 安全/语义修复回归：destroy 后不得复活、regenerate 默认删除旧数据、
 * FileDriver 不再 eval 旧格式、CookieDriver 篡改容错与跨请求状态重置、
 * 压缩载荷不再实例化任意对象。
 */
class SessionFixesTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = sys_get_temp_dir() . '/kode_fixes_test_' . uniqid();
        mkdir($this->tempPath, 0700, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempPath)) {
            return;
        }

        foreach (glob($this->tempPath . '/{,locks/}*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }

        @rmdir($this->tempPath . '/locks');
        @rmdir($this->tempPath);
    }

    public function testDestroyClearsStartedSoSaveCannotResurrect(): void
    {
        $driver = new ArrayDriver();
        $session = new Session(bin2hex(random_bytes(16)), 'S', $driver);
        $session->start();
        $session->set('a', 1);
        $session->save();

        $session->start();
        $this->assertTrue($session->destroy());
        $this->assertFalse($session->isStarted());

        // 中间件收尾的 save() 必须是无操作，不得把已销毁会话重新落盘
        $session->save();
        $verifier = new Session($session->getId(), 'S', $driver);
        $verifier->start();
        $this->assertSame([], $verifier->all());
    }

    public function testRegenerateDeletesOldDataByDefault(): void
    {
        $driver = new ArrayDriver();
        $id = bin2hex(random_bytes(16));
        $session = new Session($id, 'S', $driver);
        $session->start();
        $session->set('user_id', 7);
        $session->save();

        $session->start();
        $this->assertTrue($session->regenerate());
        $session->save();

        $this->assertNotSame($id, $session->getId());
        $this->assertFalse($driver->exists($id), '旧 ID 数据默认应被删除（防会话固定残留）');
    }

    public function testFileDriverRefusesLegacyEvalFormat(): void
    {
        $driver = new FileDriver(['path' => $this->tempPath, 'prefix' => 'fx_']);
        $id = bin2hex(random_bytes(16));
        $marker = $this->tempPath . '/pwned.txt';

        // 2.x 旧格式：负载位置放一条有副作用的表达式，若被 eval 求值将留下文件
        $legacy = '<?php return array(\'evil\' => file_put_contents(\'' . $marker . '\', \'x\'));';
        file_put_contents($this->tempPath . '/fx_' . $id . '.php', $legacy);

        $this->assertSame([], $driver->all($id));
        $this->assertFileDoesNotExist($marker, '旧格式负载不得被求值执行');
    }

    public function testCookieDriverSurvivesTamperedFieldTypes(): void
    {
        // expire 被篡改为字符串：strict_types 下曾直接 TypeError 变 500
        $payload = base64_encode(json_encode([
            'id' => 'abc',
            'expire' => '1',
            'data' => ['x' => ['data' => 'v', 'expire' => 0]],
            'sig' => 'deadbeef',
        ]));

        $driver = new CookieDriver([
            'name' => 'kode_test',
            'secret' => 's3cret',
            'cookie_data' => ['kode_test' => $payload],
        ]);

        $this->assertSame([], $driver->all('abc'));
    }

    public function testCookieDriverOpenResetsPerRequestState(): void
    {
        $cookie = base64_encode(json_encode([
            'id' => 'sid1',
            'expire' => 0,
            'data' => ['orig' => ['data' => 'o', 'expire' => 0]],
        ]));

        $driver = new CookieDriver(['name' => 'kode_test', 'cookie_data' => ['kode_test' => $cookie]]);

        // 模拟请求收尾写入新键后，常驻实例被下一个请求复用
        $driver->set('sid1', 'leaked', 'L', 0);
        $this->assertSame('L', $driver->get('sid1', 'leaked'));

        $this->assertTrue($driver->open('sid2'));
        $this->assertNull($driver->get('sid2', 'leaked'), 'open() 后不得残留上一请求的写入');
        $this->assertSame('o', $driver->get('sid2', 'orig'));
    }

    public function testCompressedPayloadDoesNotInstantiateObjects(): void
    {
        $driverA = new ArrayDriver(['compress' => true]);
        $id = bin2hex(random_bytes(16));
        $driverA->set($id, 'obj', new \stdClass(), 0);

        $value = $driverA->get($id, 'obj');

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $value);
    }
}
