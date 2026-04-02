# kode/session

高性能分布式会话管理器，支持文件存储、Redis 存储等多种驱动，可独立使用或集成到其他框架中。

## 特性

- **多驱动支持**：File、Redis 等存储驱动
- **分布式会话**：Redis 驱动支持跨机器共享 session
- **进程/并行支持**：支持多进程并发访问，带分布式锁
- **Fiber 安全**：在 PHP Fiber 中安全使用 session
- **PSR-7/15 兼容**：完整的中间件支持
- **Laravel/ThinkPHP 风格**：API 设计参考主流框架
- **PHP 8.1+**：使用 readonly、enum 等新特性

## 安装

```bash
composer require kode/session
```

## 快速开始

### 基本用法

```php
<?php

use Kode\Session\SessionManager;
use Kode\Session\Driver\FileDriver;

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
$session->has('key');            // 检查是否存在
$session->delete('key');         // 删除
$session->clear();               // 清空所有数据

$session->all();                 // 获取所有数据
$session->pull('key');            // 获取并删除
```

#### 闪存数据

闪存数据只在当前请求和下一次请求中可用。

```php
$session->flash('success', '操作成功');

$session->retainFlash();   // 保留闪存数据（用于重定向场景）
$session->flushFlash();     // 清空所有闪存数据
$session->ageFlash();       // 将新闪存转为旧闪存
```

#### 快捷方法

```php
$session->remember('key', function () {
    return $cache->get('key');
});

$session->pull('key', 'default');  // 获取并删除
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
    // 原子操作
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

### Fiber 支持

```php
$fiber = $parallel->async(function ($session) {
    FiberSessionStorage::set('session', $session);
    return $session->get('user_id');
});

$result = $fiber->start();
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

        // 处理请求...
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
│   ├── Driver.php        # 驱动接口
│   ├── Session.php       # Session 接口
│   └── SessionFactory.php # 工厂接口
├── Driver/
│   ├── AbstractDriver.php # 驱动基类
│   ├── FileDriver.php     # 文件驱动
│   └── RedisDriver.php    # Redis 驱动
├── Middleware/
│   └── SessionMiddleware.php # PSR-15 中间件
├── Support/
│   ├── FiberSessionStorage.php # Fiber 存储
│   └── ParallelSession.php     # 并行处理
├── Session.php           # Session 类
└── SessionManager.php    # 管理器
```

## 测试

```bash
./vendor/bin/phpunit
```

## 性能提示

1. **File 驱动**：适合开发环境和小规模部署
2. **Redis 驱动**：生产环境推荐，支持分布式和高并发
3. **GC 回收**：定期运行垃圾回收清理过期 session

## 驱动扩展

如需添加新的驱动，只需实现 `Kode\Session\Contract\Driver` 接口：

```php
use Kode\Session\Contract\Driver;

class CustomDriver implements Driver
{
    public function __construct(array $config = [])
    {
        // 初始化配置
    }

    public function get(string $id, string $name, mixed $default = null): mixed
    {
        // 获取 session 值
    }

    // ... 实现其他接口方法
}
```

然后注册到 SessionManager：

```php
$manager->extend('custom', function ($config) {
    return new CustomDriver($config);
});
```

## 版本历史

- **v1.0.1** - 修复未使用变量问题，优化代码结构
- **v1.0.0** - 初始版本，支持 File 和 Redis 驱动

## 许可证

Apache License 2.0 - see [LICENSE](LICENSE)
