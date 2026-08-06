# kode/session

高性能分布式会话管理器，支持文件、Redis、Cookie 等多种驱动，可独立使用或集成到其他框架中。

## 特性

- **多驱动支持**：File、Redis、Cookie、Array（内存）等存储驱动
- **分布式会话**：Redis 驱动支持跨机器共享 session
- **协程安全**：不使用全局 `$_SESSION`，支持 PHP Fiber/协程
- **请求隔离**：支持配合 kode/context 做请求内会话隔离
- **进程/并行支持**：支持多进程并发访问，带分布式锁
- **PSR-7/15 兼容**：完整的中间件支持
- **闪存数据**：类似 Laravel/ThinkPHP 的 flash 功能（支持 `now`/`old`/`keep`）
- **安全加固**：会话 ID 强校验（防路径穿越与会话固定）、Cookie 驱动 HMAC 签名防篡改、`regenerate` 数据迁移
- **异常体系**：分层异常（`SessionException` 及子类）便于精准捕获
- **PHP 8.3+**：使用枚举、只读属性、`#[\Override]` 等现代特性

## 安装

```bash
composer require kode/session
```

## 快速开始

### 基本用法

```php
<?php

use Kode\Session\SessionManager;

$manager = new SessionManager([
    'default' => 'file',
    'drivers' => [
        'file' => [
            'path' => '/tmp/sessions',
            'prefix' => 'sess_',
        ],
    ],
]);

$session = $manager->make(bin2hex(random_bytes(16)));
$session->start();

$session->set('user_id', 123);
$session->set('username', 'kode');

echo $session->get('username');

$session->close();
```

### 使用中间件

```php
<?php

use Kode\Session\SessionManager;
use Kode\Session\Middleware\SessionMiddleware;

$manager = new SessionManager([...]);

$middleware = new SessionMiddleware($manager, [
    'name' => 'KODE_SESSION',
    'lifetime' => 3600,
    'path' => '/',
    'secure' => false,
    'http_only' => true,
]);
```

## 驱动

### File 驱动

本地文件存储，适合单机部署。

```php
use Kode\Session\Driver\FileDriver;

$driver = new FileDriver([
    'path' => '/tmp/sessions',
    'prefix' => 'kode_sess_',
    'lock_path' => '/tmp/sessions/locks',
]);
```

### Redis 驱动

分布式存储，适合多机器部署。

```php
use Kode\Session\Driver\RedisDriver;

$driver = new RedisDriver([
    'prefix' => 'kode_sess_',
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => null,
        'database' => 0,
    ],
]);
```

Redis 驱动支持两种连接方式：
- phpredis 扩展（优先）
- predis 包（`composer require predis/predis`）

### Cookie 驱动

基于客户端 Cookie 存储，适合轻量级场景。

```php
use Kode\Session\Driver\CookieDriver;

$driver = new CookieDriver([
    'name' => 'kode_session',
    'lifetime' => 3600,
    'path' => '/',
    'secure' => false,
    'http_only' => true,
    'samesite' => 'Lax',
]);
```

注意：Cookie 有大小限制（通常 4KB），只适合存储少量数据。

### Array 驱动

纯内存存储，适合测试、CLI 与临时会话（进程结束即释放）。

```php
use Kode\Session\Driver\ArrayDriver;

$driver = new ArrayDriver(); // 可选传入 ['gc_probability' => 100] 强制每次 GC
```

## 驱动列表

| 驱动 | 说明 | 使用场景 |
|------|------|----------|
| File | 本地文件存储 | 单机部署、开发环境 |
| Redis | 分布式存储 | 生产环境、多机器部署 |
| Cookie | 客户端存储 | 轻量级场景、简单数据 |
| Array | 内存存储 | 单元测试、CLI、临时会话 |

## 配置

### SessionManager 配置

```php
$manager = new SessionManager([
    'default' => 'file',
    'drivers' => [
        'file' => [
            'path' => '/tmp/sessions',
            'prefix' => 'kode_sess_',
        ],
        'redis' => [
            'prefix' => 'kode_sess_',
            'redis' => [
                'host' => '127.0.0.1',
                'port' => 6379,
            ],
        ],
        'cookie' => [
            'name' => 'kode_session',
            'lifetime' => 3600,
        ],
    ],
]);
```

### 中间件配置

```php
$middleware = new SessionMiddleware($manager, [
    'driver' => 'file',
    'name' => 'KODE_SESSION',
    'lifetime' => 3600,
    'path' => '/',
    'domain' => null,
    'secure' => false,
    'http_only' => true,
    'auto_start' => true,
]);
```

## API 文档

### Session 类

#### 基本操作

```php
$session->start();     // 启动 session
$session->close();     // 关闭 session
$session->destroy();   // 销毁 session
$session->regenerate(); // 重新生成 session ID

$session->getId();     // 获取 session ID
$session->getName();   // 获取 session 名称
$session->isStarted();  // 检查是否已启动
```

#### 数据存取

```php
$session->set('key', 'value');  // 设置值
$session->get('key');            // 获取值
$session->get('key', 'default'); // 获取值，不存在则返回默认值
$session->has('key');            // 检查是否存在（值为 null 也算存在）
$session->delete('key');         // 删除
$session->clear();               // 清空所有数据

$session->all();                 // 获取所有数据
$session->pull('key');            // 获取并删除
```

#### 进阶操作

```php
$session->push('list', 'a');     // 向数组键追加值（自动初始化为数组）
$session->increment('counter');  // 自增 1，返回结果（非数字按 0 处理）
$session->increment('counter', 5);
$session->decrement('counter');  // 自减 1
$session->only(['a', 'b']);      // 仅返回指定键
$session->except(['a']);         // 排除指定键
$session->replace(['x' => 1]);   // 整体替换为新数据
$session->forget('key');         // 删除单个键
$session->forget(['a', 'b']);    // 批量删除
$session->flush();               // 清空（clear 的别名）
```

#### 闪存数据

闪存数据只在当前请求和下一次请求中可用。

```php
$session->flash('success', '操作成功');  // 下一请求可用，之后自动删除
$session->flash('tip', null);            // 显式 null 也可存储（区分「未设置」）
$session->now('temp', '仅本请求');        // 仅当前请求可用，下一请求即失效
$session->old('success');                 // 读取上一次请求的闪存

$session->keep(['success']);   // 保留指定闪存多存活一轮（重定向场景）
$session->retainFlash();       // 保留全部闪存多存活一轮
$session->flushFlash();        // 清空所有闪存数据
$session->ageFlash();          // 将新闪存转为旧闪存
```

#### 错误/成功信息

```php
$session->setError('email', '邮箱格式不正确');
$session->hasError('email');
$session->getError('email');

$session->setSuccess('saved', '保存成功');
$session->hasSuccess('saved');
```

#### CSRF 保护

```php
$token = $session->token();              // 获取 CSRF token
$session->token($newToken);              // 设置 token
$session->verifyCsrfToken($token);        // 验证 token
```

### SessionManager 类

```php
$manager->make($id, $config);           // 创建 session
$manager->fromRequest($config);         // 从请求创建（自动获取 session ID）
$manager->getDriver($name);              // 获取驱动
$manager->createId();                    // 创建新 session ID
$manager->getConfig('key');              // 获取配置
$manager->setConfig('key', $value);      // 设置配置
```

## 协程安全

本包不使用全局 `$_SESSION`，完全在内存中管理 session 数据，支持 PHP Fiber/协程。

### Fiber 中的使用

```php
use Kode\Session\Support\FiberSessionStorage;

$fiber = new \Fiber(function () {
    $session = FiberSessionStorage::get('session');

    if ($session === null) {
        return;
    }

    $session->set('user_id', 123);
});

$fiber->start();
```

## 请求隔离

配合 kode/context 做请求内会话隔离：

```php
use Kode\Session\Support\ContextSession;

$session = $manager->make($sessionId);
$session->start();

ContextSession::setSession($session);
ContextSession::set('request_id', uniqid());

$fiber = new \Fiber(function () {
    $session = ContextSession::getSession();
    $requestId = ContextSession::get('request_id');

    var_dump($requestId);
});

$fiber->start();
```

## 分布式和并行

### 分布式锁

Redis 驱动支持分布式锁：

```php
use Kode\Session\Support\ParallelSession;

$parallel = new ParallelSession($manager, [
    'driver' => 'redis',
]);

$parallel->create($sessionId);

$result = $parallel->withLock(function ($session) {
    return $session->get('counter');
}, 10);
```

### 多进程支持

```php
$result = $parallel->fork(function ($session) {
    $session->set('worker_id', getmypid());
    return $session->get('worker_id');
}, ['shared_data' => 'value']);
```

## 框架集成

### 集成到自定义框架

```php
class Application
{
    protected SessionManager $session;

    public function handleRequest($request)
    {
        $this->session = $this->sessionManager->fromRequest([
            'name' => 'APP_SESSION',
            'lifetime' => 3600,
        ]);

        $this->session->start();
    }

    public function terminate($response)
    {
        if ($this->session?->isStarted()) {
            $this->session->save();
            $this->session->close();
        }
    }
}
```

## 目录结构

```
src/
├── Contract/
│   ├── Driver.php          # 驱动接口
│   ├── Session.php         # Session 接口
│   └── SessionFactory.php   # 工厂接口
├── Driver/
│   ├── AbstractDriver.php   # 驱动基类（wrap/unwrap、锁、GC 等公共逻辑）
│   ├── ArrayDriver.php      # 内存驱动
│   ├── CookieDriver.php     # Cookie 驱动（HMAC 签名）
│   ├── FileDriver.php       # 文件驱动
│   └── RedisDriver.php      # Redis 驱动
├── Exception/
│   ├── SessionException.php        # 基类
│   ├── DriverNotFoundException.php
│   ├── InvalidSessionIdException.php
│   └── LockException.php
├── Middleware/
│   └── SessionMiddleware.php # PSR-15 中间件
├── Support/
│   ├── ContextSession.php     # Context 隔离
│   ├── FiberSessionStorage.php # Fiber 存储（WeakMap）
│   ├── ParallelSession.php     # 并行处理
│   └── SessionId.php           # ID 生成与强校验
├── DriverType.php           # 驱动类型枚举
├── Session.php             # Session 类
└── SessionManager.php      # 管理器
```

## 测试

```bash
./vendor/bin/phpunit
```

## 性能提示

1. **File 驱动**：适合开发环境和小规模部署
2. **Redis 驱动**：生产环境推荐，支持分布式和高并发
3. **Cookie 驱动**：仅用于轻量级场景，不适合存储大量数据
4. **GC 回收**：定期运行垃圾回收清理过期 session

## 驱动扩展

如需添加新的驱动，只需实现 `Kode\Session\Contract\Driver` 接口：

```php
use Kode\Session\Contract\Driver;

class CustomDriver implements Driver
{
    public function __construct(array $config = [])
    {
    }

    public function get(string $id, string $name, mixed $default = null): mixed
    {
    }

    public function set(string $id, string $name, mixed $value, int $lifetime = 0): bool
    {
    }

    public function setMultiple(string $id, array $values, int $lifetime = 0): bool
    {
    }

    public function delete(string $id, string $name): bool
    {
    }

    public function has(string $id, string $name): bool
    {
    }

    public function exists(string $id): bool
    {
    }

    public function clear(string $id): bool
    {
    }

    public function pull(string $id, string $name, mixed $default = null): mixed
    {
    }

    public function remember(string $id, string $name, callable $callback, int $lifetime = 0): mixed
    {
    }

    public function migrate(string $fromId, string $toId, bool $delete = true): bool
    {
    }

    public function open(string $id): bool
    {
    }

    public function close(string $id): bool
    {
    }

    public function destroy(string $id): bool
    {
    }

    public function gc(int $maxLifetime): int
    {
    }

    public function all(string $id): array
    {
    }

    public function generateId(): string
    {
    }

    public function acquireLock(string $id, ?int $timeout = null): bool
    {
    }

    public function releaseLock(string $id): bool
    {
    }
}
```

然后注册到 SessionManager（通过 `extend()` 注册的回调返回 `Driver` 实例，已可正确生效）：

```php
$manager->extend('custom', function (array $config) {
    return new CustomDriver($config);
});
```

## 许可证

Apache License 2.0 - see [LICENSE](LICENSE)
