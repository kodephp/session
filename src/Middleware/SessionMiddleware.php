<?php

declare(strict_types=1);

namespace Kode\Session\Middleware;

use Kode\Session\Session;
use Kode\Session\SessionManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Session 中间件 - 自动开启和关闭 session
 * 实现 PSR-15 中间件接口
 *
 * @author kode
 */
class SessionMiddleware implements MiddlewareInterface
{
    /**
     * Session 管理器
     */
    protected SessionManager $manager;

    /**
     * 配置
     */
    protected array $config;

    /**
     * 自动开启 session
     */
    protected bool $autoStart = true;

    /**
     * 构造函数
     *
     * @param SessionManager $manager Session 管理器
     * @param array          $config  配置参数
     */
    public function __construct(SessionManager $manager, array $config = [])
    {
        $this->manager = $manager;
        $this->config = $config;
        $this->autoStart = $config['auto_start'] ?? true;
    }

    /**
     * 处理请求
     *
     * @param ServerRequestInterface $request 请求
     * @param RequestHandlerInterface $handler 处理器
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $this->manager->fromRequest($this->config);
        $this->manager->setSession($session);

        if ($this->autoStart && !$session->isStarted()) {
            $session->start();
        }

        $request = $request->withAttribute('session', $session);

        try {
            $response = $handler->handle($request);

            if ($session->isStarted()) {
                $this->saveSession($session, $response);
            }

            return $response;
        } finally {
            if ($session->isStarted()) {
                $session->close();
            }
        }
    }

    /**
     * 保存 session 到响应
     *
     * @param Session         $session  session 实例
     * @param ResponseInterface $response 响应
     * @return ResponseInterface
     */
    protected function saveSession(Session $session, ResponseInterface $response): ResponseInterface
    {
        $session->save();

        $cookieName = $session->getName();
        $cookieValue = $session->getId();
        $cookieLifetime = $this->config['lifetime'] ?? 0;
        $cookiePath = $this->config['path'] ?? '/';
        $cookieDomain = $this->config['domain'] ?? null;
        $cookieSecure = $this->config['secure'] ?? false;
        $cookieHttpOnly = $this->config['http_only'] ?? true;

        $setCookieHeader = sprintf(
            '%s=%s; Path=%s%s%s%s; SameSite=Lax',
            $cookieName,
            $cookieValue,
            $cookiePath,
            $cookieDomain !== null ? '; Domain=' . $cookieDomain : '',
            $cookieSecure ? '; Secure' : '',
            $cookieHttpOnly ? '; HttpOnly' : ''
        );

        if ($cookieLifetime > 0) {
            $setCookieHeader .= '; Expires=' . gmdate('D, d-M-Y H:i:s T', time() + $cookieLifetime);
        }

        return $response->withHeader('Set-Cookie', $setCookieHeader);
    }
}
