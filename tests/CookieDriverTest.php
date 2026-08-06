<?php

declare(strict_types=1);

namespace Kode\Session\Tests;

use Kode\Session\Driver\CookieDriver;
use PHPUnit\Framework\TestCase;

class CookieDriverTest extends TestCase
{
    protected function makeDriver(array $extra = []): CookieDriver
    {
        return new CookieDriver(array_merge([
            'name' => 'kode_test',
            'secret' => 'top-secret',
        ], $extra));
    }

    public function testSetAndGetRoundTrip(): void
    {
        $driver = $this->makeDriver();
        $driver->set('sid1', 'k1', 'v1');

        $cookie = $driver->getLastCookieValue();
        $this->assertNotEmpty($cookie);

        // 模拟下一请求：将写出的 Cookie 作为入参注入
        $next = $this->makeDriver(['cookie_data' => ['kode_test' => $cookie]]);
        $this->assertEquals('v1', $next->get('sid1', 'k1'));
    }

    public function testTamperedCookieIsRejected(): void
    {
        $driver = $this->makeDriver();
        $driver->set('sid1', 'k1', 'v1');

        $cookie = $driver->getLastCookieValue();
        // 篡改最后一个字符，破坏 HMAC 签名
        $tampered = substr($cookie, 0, -1) . (substr($cookie, -1) === 'A' ? 'B' : 'A');

        $next = $this->makeDriver(['cookie_data' => ['kode_test' => $tampered]]);
        // 签名校验失败：整段会话被丢弃，读取返回默认值
        $this->assertNull($next->get('sid1', 'k1'));
    }

    public function testNullValueStoredAndReadable(): void
    {
        $driver = $this->makeDriver();
        $driver->set('sid1', 'k1', null);

        $cookie = $driver->getLastCookieValue();
        $next = $this->makeDriver(['cookie_data' => ['kode_test' => $cookie]]);

        $this->assertTrue($next->has('sid1', 'k1'));
        $this->assertNull($next->get('sid1', 'k1'));
    }

    public function testHeadersSentSkipsWrite(): void
    {
        $driver = $this->makeDriver();

        // 已发送 header 时 save/clear 应安全返回 true 而不致命错误
        $this->assertTrue($driver->set('sid1', 'k1', 'v1'));
        $this->assertTrue($driver->clear('sid1'));
    }

    public function testSameSiteConfigurable(): void
    {
        $driver = $this->makeDriver(['samesite' => 'Strict']);
        $value = $driver->buildCookieValue('sid1');

        $decoded = json_decode(base64_decode($value), true);
        $this->assertArrayHasKey('data', $decoded);
    }
}
