<?php

declare(strict_types=1);

namespace Kode\Session\Exception;

/**
 * 驱动不存在异常
 *
 * @author kode
 */
final class DriverNotFoundException extends SessionException
{
    /**
     * 根据驱动名创建异常
     *
     * @param string        $name      驱动名称
     * @param array<string> $available 可用驱动列表
     */
    public static function forDriver(string $name, array $available = []): self
    {
        $message = "不支持的驱动: {$name}";

        if ($available !== []) {
            $message .= '，可用驱动: ' . implode(', ', $available);
        }

        return new self($message);
    }
}
