<?php

declare(strict_types=1);

namespace Kode\Session\Support;

use Kode\Session\Exception\InvalidSessionIdException;

/**
 * Session ID 生成与校验
 *
 * 客户端可控的 Session ID 若不加校验，会直接拼进文件路径 / Redis Key，
 * 造成路径穿越与键注入；本类是所有 ID 进入存储层前的唯一入口。
 *
 * @author kode
 */
final class SessionId
{
    /**
     * 合法 ID 正则：仅允许 URL 安全字符
     */
    public const string PATTERN = '/^[A-Za-z0-9_\-]{16,128}$/';

    /**
     * 最短长度
     */
    public const int MIN_LENGTH = 16;

    /**
     * 最长长度
     */
    public const int MAX_LENGTH = 128;

    /**
     * 默认随机字节数（生成 32 位十六进制字符串）
     */
    public const int DEFAULT_BYTES = 16;

    /**
     * 禁止实例化
     */
    private function __construct()
    {
    }

    /**
     * 生成加密安全的 Session ID
     *
     * @param int $bytes 随机字节数
     */
    public static function generate(int $bytes = self::DEFAULT_BYTES): string
    {
        $bytes = max(8, min(64, $bytes));

        return bin2hex(random_bytes($bytes));
    }

    /**
     * 校验 ID 是否合法
     *
     * @param string $id 待校验 ID
     */
    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }

    /**
     * 断言 ID 合法，非法则抛出异常
     *
     * @param string $id 待校验 ID
     * @throws InvalidSessionIdException
     */
    public static function assert(string $id): string
    {
        if (!self::isValid($id)) {
            throw InvalidSessionIdException::forId($id);
        }

        return $id;
    }

    /**
     * 净化 ID：合法则原样返回，非法则生成新 ID
     *
     * 用于处理不可信来源（Cookie / Query / Header），避免直接抛异常打断请求
     *
     * @param string|null $id 不可信 ID
     */
    public static function sanitize(?string $id): string
    {
        if ($id !== null && self::isValid($id)) {
            return $id;
        }

        return self::generate();
    }
}
