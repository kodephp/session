<?php

declare(strict_types=1);

namespace Kode\Session\Exception;

/**
 * 锁异常（获取失败 / 超时）
 *
 * @author kode
 */
final class LockException extends SessionException
{
    /**
     * 获取锁超时
     *
     * @param string $id      Session ID
     * @param int    $timeout 超时秒数
     */
    public static function timeout(string $id, int $timeout): self
    {
        return new self("获取会话锁超时（{$timeout}s）: {$id}");
    }

    /**
     * 无法创建锁资源
     *
     * @param string $resource 锁资源标识
     */
    public static function unavailable(string $resource): self
    {
        return new self("无法创建锁资源: {$resource}");
    }
}
