<?php

declare(strict_types=1);

namespace Kode\Session\Contract;

use Kode\Session\Session;

/**
 * Session 工厂接口
 * 用于创建 session 实例
 *
 * @author kode
 */
interface SessionFactory
{
    /**
     * 创建 session 实例
     *
     * @param string $id     Session ID
     * @param array  $config 配置参数
     * @return Session
     */
    public function make(string $id, array $config = []): Session;
}
