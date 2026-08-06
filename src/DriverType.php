<?php

declare(strict_types=1);

namespace Kode\Session;

/**
 * 内置驱动类型枚举
 * 用于替代散落的字符串字面量，获得 IDE 补全与静态检查
 *
 * @author kode
 */
enum DriverType: string
{
    /** 本地文件存储 */
    case File = 'file';

    /** Redis 分布式存储 */
    case Redis = 'redis';

    /** 客户端 Cookie 存储 */
    case Cookie = 'cookie';

    /** 进程内内存存储 */
    case Array_ = 'array';

    /**
     * 默认驱动
     */
    public const self DEFAULT = self::File;

    /**
     * 获取全部内置驱动名称
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * 是否为内置驱动名
     *
     * @param string $name 驱动名称
     */
    public static function supports(string $name): bool
    {
        return self::tryFrom($name) !== null;
    }

    /**
     * 该驱动是否支持真正的分布式共享
     */
    public function isDistributed(): bool
    {
        return $this === self::Redis;
    }

    /**
     * 该驱动是否具备服务端持久化能力
     */
    public function isPersistent(): bool
    {
        return match ($this) {
            self::File, self::Redis => true,
            self::Cookie, self::Array_ => false,
        };
    }
}
