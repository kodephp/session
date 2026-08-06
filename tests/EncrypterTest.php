<?php

declare(strict_types=1);

namespace Kode\Session\Tests;

use Kode\Session\Support\Encrypter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Encrypter 单元测试：AES-256-GCM 透明加密
 */
class EncrypterTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $enc = new Encrypter('shared-secret');

        $value = ['user_id' => 42, 'name' => 'bob', 'roles' => ['admin', 'user']];
        $payload = $enc->encrypt($value);
        $decoded = $enc->decrypt($payload);

        $this->assertSame($value, $decoded);
    }

    public function testEncryptHandlesScalarTypes(): void
    {
        $enc = new Encrypter('secret');

        $this->assertSame(123, $enc->decrypt($enc->encrypt(123)));
        $this->assertSame('hello', $enc->decrypt($enc->encrypt('hello')));
        $this->assertSame(3.14, $enc->decrypt($enc->encrypt(3.14)));
        $this->assertNull($enc->decrypt($enc->encrypt(null)));
        $this->assertTrue($enc->decrypt($enc->encrypt(true)));
    }

    public function testEncryptProducesUniqueCiphertextPerCall(): void
    {
        $enc = new Encrypter('secret');

        $a = $enc->encrypt('same-value');
        $b = $enc->encrypt('same-value');

        // 随机 IV 保证相同明文产生不同密文，防重放
        $this->assertNotSame($a, $b);
        $this->assertSame('same-value', $enc->decrypt($a));
        $this->assertSame('same-value', $enc->decrypt($b));
    }

    public function testIsEncryptedDetectsPrefix(): void
    {
        $enc = new Encrypter('secret');
        $payload = $enc->encrypt('x');

        $this->assertTrue(Encrypter::isEncrypted($payload));
        $this->assertTrue(Encrypter::isEncrypted('kenc1:not-base64!!'));
        $this->assertFalse(Encrypter::isEncrypted('plaintext'));
    }

    public function testDecryptNonCiphertextReturnsNull(): void
    {
        $enc = new Encrypter('secret');

        $this->assertNull($enc->decrypt('not-a-ciphertext'));
    }

    public function testDecryptTamperedCiphertextReturnsNull(): void
    {
        $enc = new Encrypter('secret');
        $payload = $enc->encrypt('original');

        // 篡改末尾字符，GCM 认证标签校验失败
        $tampered = substr($payload, 0, -1) . (substr($payload, -1) === 'A' ? 'B' : 'A');
        $this->assertNull($enc->decrypt($tampered));
    }

    public function testDecryptWithWrongKeyReturnsNull(): void
    {
        $enc1 = new Encrypter('secret-one');
        $enc2 = new Encrypter('secret-two');

        $payload = $enc1->encrypt('classified');

        $this->assertNull($enc2->decrypt($payload));
    }

    public function testEmptySecretThrows(): void
    {
        $this->expectException(RuntimeException::class);

        new Encrypter('');
    }
}
