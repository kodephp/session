<?php

declare(strict_types=1);

namespace Kode\Session\Exception;

/**
 * 非法 Session ID 异常
 * 用于阻断路径穿越、注入等来自客户端的恶意 ID
 *
 * @author kode
 */
final class InvalidSessionIdException extends SessionException
{
    /**
     * 根据非法 ID 创建异常
     *
     * @param string $id 非法的 Session ID
     */
    public static function forId(string $id): self
    {
        $preview = substr($id, 0, 32);

        return new self("非法的 Session ID: {$preview}");
    }
}
