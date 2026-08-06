<?php

declare(strict_types=1);

namespace Kode\Session\Support;

use RuntimeException;

/**
 * 会话数据加解密器（AES-256-GCM）
 *
 * 透明地加密「写入存储层」的会话值，使落入文件 / Redis / Cookie / 数据库中的
 * 数据均为密文，即使存储介质泄漏也无法还原明文。
 *
 * - 密钥由 secret 经 PBKDF2 衍生为 32 字节，避免用户直接使用弱密钥
 * - 每条密文携带独立随机 IV 与 GCM 认证标签，防重放与篡改
 * - 解密失败（密钥不符 / 数据被篡改）时返回 null，由上层降级处理
 *
 * @author kode
 */
final class Encrypter
{
    /**
     * GCM 推荐 IV 长度（字节）
     */
    private const int IV_LENGTH = 12;

    /**
     * GCM 认证标签长度（字节）
     */
    private const int TAG_LENGTH = 16;

    /**
     * 衍生密钥长度（字节，AES-256）
     */
    private const int KEY_LENGTH = 32;

    /**
     * PBKDF2 迭代次数
     */
    private const int PBKDF2_ITERATIONS = 10000;

    /**
     * 密文前缀（用于快速识别本包密文，便于向后兼容非加密数据）
     */
    public const string PREFIX = 'kenc1:';

    /**
     * 衍生密钥
     *
     * @var non-empty-string
     */
    private string $key;

    /**
     * @param string $secret 用户提供的密钥材料（任意长度字符串）
     * @throws RuntimeException 缺少 openssl 扩展时
     */
    public function __construct(string $secret)
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('启用会话加密需要 openssl 扩展');
        }

        if ($secret === '') {
            throw new RuntimeException('加密密钥 secret 不能为空');
        }

        $this->key = hash_pbkdf2(
            'sha256',
            $secret,
            'kode-session-encryption',
            self::PBKDF2_ITERATIONS,
            self::KEY_LENGTH,
            true
        );
    }

    /**
     * 加密任意可序列化值，返回带前缀的 base64 字符串
     *
     * @param mixed $value 待加密值
     */
    public function encrypt(mixed $value): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $plaintext = serialize($value);

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new RuntimeException('会话数据加密失败');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * 解密，失败返回 null
     *
     * @param string $payload 密文（须带本包前缀）
     * @return mixed|null
     */
    public function decrypt(string $payload): mixed
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= self::IV_LENGTH + self::TAG_LENGTH) {
            return null;
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            return null;
        }

        $value = @unserialize($plaintext, ['allowed_classes' => true]);

        return $value !== false ? $value : null;
    }

    /**
     * 判断字符串是否为本包密文
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }
}
