<?php

declare(strict_types=1);

namespace Kode\Session\Exception;

use RuntimeException;

/**
 * Session 基础异常
 * 本包抛出的所有异常均继承自此类，便于上层统一捕获
 *
 * @author kode
 */
class SessionException extends RuntimeException
{
}
