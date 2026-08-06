<?php

declare(strict_types=1);

namespace Kode\Session\Tests;

use Kode\Session\Driver\FileDriver;
use Kode\Session\Exception\InvalidSessionIdException;
use Kode\Session\Support\SessionId;
use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase
{
    protected string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = sys_get_temp_dir() . '/kode_security_test_' . uniqid();
        mkdir($this->tempPath, 0755, true);
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

    public function testGeneratedIdIsValid(): void
    {
        $id = SessionId::generate();

        $this->assertMatchesRegularExpression(SessionId::PATTERN, $id);
        $this->assertTrue(SessionId::isValid($id));
    }

    public static function invalidIds(): array
    {
        return [
            'path traversal' => ['../../etc/passwd'],
            'null byte' => ["abc\x00def"],
            'sql injection' => ["1' OR '1'='1"],
            'too short' => ['abc'],
            'space' => ['my session id'],
        ];
    }

    /**
     * @dataProvider invalidIds
     */
    public function testInvalidIdsAreRejected(string $id): void
    {
        $this->assertFalse(SessionId::isValid($id));
    }

    public function testFileDriverRejectsInvalidIdOnRead(): void
    {
        $driver = new FileDriver(['path' => $this->tempPath, 'prefix' => 'test_']);

        $this->expectException(InvalidSessionIdException::class);
        $driver->get('../../etc/passwd', 'key');
    }

    public function testFileDriverRejectsInvalidIdOnWrite(): void
    {
        $driver = new FileDriver(['path' => $this->tempPath, 'prefix' => 'test_']);

        $this->expectException(InvalidSessionIdException::class);
        $driver->set('../../etc/passwd', 'key', 'value');
    }

    public function testFileDriverRejectsInvalidIdOnExists(): void
    {
        $driver = new FileDriver(['path' => $this->tempPath, 'prefix' => 'test_']);

        $this->expectException(InvalidSessionIdException::class);
        $driver->exists('..%2f..%2fetc');
    }

    public function testValidIdStillWorks(): void
    {
        $driver = new FileDriver(['path' => $this->tempPath, 'prefix' => 'test_']);
        $id = SessionId::generate();

        $driver->set($id, 'key', 'value');
        $this->assertEquals('value', $driver->get($id, 'key'));
    }

    public function testRegenerateChangesIdToPreventFixation(): void
    {
        $driver = new FileDriver(['path' => $this->tempPath, 'prefix' => 'test_']);
        $id = SessionId::generate();

        $session = new \Kode\Session\Session($id, 'SEC', $driver);
        $session->start();
        $session->set('user', 'bob');
        $session->regenerate(true);

        $newId = $session->getId();
        $this->assertNotEquals($id, $newId);
        $this->assertTrue(SessionId::isValid($newId));

        $session->save();

        // 新 ID 可恢复数据，旧 ID 已不可达
        $restored = new \Kode\Session\Session($newId, 'SEC', $driver);
        $restored->start();
        $this->assertEquals('bob', $restored->get('user'));
        $this->assertFalse($driver->exists($id));
    }
}
