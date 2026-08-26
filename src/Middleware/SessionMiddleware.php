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

            $this->maybeGarbageCollect();

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
     * 按概率触发垃圾回收，避免每请求都扫描过期数据
     *
     * 配置项：gc_probability（默认 10）、gc_divisor（默认 100）、gc_lifetime（默认取 lifetime）
     * 调整默认概率以适应高流量部署：默认 10%（而非 1%），可通过配置自定义
     */
    protected function maybeGarbageCollect(): void
    {
        $probability = (int) ($this->config['gc_probability'] ?? 10);
        $divisor = (int) ($this->config['gc_divisor'] ?? 100);

        if ($probability <= 0 || $divisor <= 0 || $probability > $divisor) {
            return;
        }

        if (random_int(1, $divisor) > $probability) {
            return;
        }

        $lifetime = (int) ($this->config['gc_lifetime'] ?? $this->config['lifetime'] ?? 0);

        $this->manager->gc($lifetime, $this->config);
    }

    /**
     * 保存 session 到响应
     *
     * @param Session            $session  session 实例
     * @param ResponseInterface  $response 响应
     * @return ResponseInterface
     */
    protected function saveSession(Session $session, ResponseInterface $response): ResponseInterface
    {
        $session->save();

        $cookieName = $session->getName();
        $cookieValue = $session->getId();
        $cookieLifetime = (int) ($this->config['lifetime'] ?? 0);
        $cookiePath = $this->config['path'] ?? '/';
        $cookieDomain = $this->config['domain'] ?? null;
        $cookieSecure = (bool) ($this->config['secure'] ?? false);
        $cookieHttpOnly = (bool) ($this->config['http_only'] ?? true);
        $cookieSameSite = $this->config['samesite'] ?? 'Lax';

        $parts = [
            $cookieName . '=' . rawurlencode($cookieValue),
            'Path=' . $cookiePath,
            'SameSite=' . $cookieSameSite,
        ];

        if ($cookieDomain !== null) {
            $parts[] = 'Domain=' . $cookieDomain;
        }

        if ($cookieSecure) {
            $parts[] = 'Secure';
        }

        if ($cookieHttpOnly) {
            $parts[] = 'HttpOnly';
        }

        if ($cookieLifetime > 0) {
            $parts[] = 'Max-Age=' . $cookieLifetime;
            $parts[] = 'Expires=' . gmdate('D, d-M-Y H:i:s T', time() + $cookieLifetime);
        }

        // 使用 withAddedHeader，避免覆盖同一响应上其它中间件的 Set-Cookie
        return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
    }
}
