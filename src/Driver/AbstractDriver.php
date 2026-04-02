<?php

declare(strict_types=1);

namespace Kode\Session\Driver;

use Kode\Session\Contract\Driver;

/**
 * 驱动基类
 * 提供驱动公共功能的默认实现
 *
 * @author kode
 */
abstract class AbstractDriver implements Driver
{
    /**
     * 配置
     */
    protected array $config;

    /**
     * 存储路径（用于 File 驱动）
     */
    protected string $path;

    /**
     * 前缀
     */
    protected string $prefix;

    /**
     * 构造函数
     *
     * @param array $config 配置数组
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->path = $config['path'] ?? sys_get_temp_dir();
        $this->prefix = $config['prefix'] ?? 'kode_session_';
    }

    /**
     * 获取并删除值（默认实现）
     *
     * @param string $id     Session ID
     * @param string $name   键名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function pull(string $id, string $name, mixed $default = null): mixed
    {
        $value = $this->get($id, $name, $default);
        $this->delete($id, $name);
        return $value;
    }

    /**
     * 不存在时执行回调并存储结果（默认实现）
     *
     * @param string   $id        Session ID
     * @param string   $name      键名
     * @param callable $callback  回调函数
     * @param int      $lifetime  生命周期
     * @return mixed
     */
    public function remember(string $id, string $name, callable $callback, int $lifetime = 0): mixed
    {
        if ($this->has($id, $name)) {
            return $this->get($id, $name);
        }

        $value = $callback();
        $this->set($id, $name, $value, $lifetime);
        return $value;
    }

    /**
     * 生成 session ID
     *
     * @return string
     */
    public function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 获取文件路径
     *
     * @param string $id Session ID
     * @return string
     */
    protected function getFilePath(string $id): string
    {
        return $this->path . DIRECTORY_SEPARATOR . $this->prefix . $id . '.php';
    }

    /**
     * 序列化数据
     *
     * @param array $data 数据
     * @return string
     */
    protected function serialize(array $data): string
    {
        return '<?php return ' . var_export($data, true) . ';';
    }

    /**
     * 反序列化数据
     *
     * @param string $file 文件路径
     * @return array
     */
    protected function unserialize(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $data = include $file;
        return is_array($data) ? $data : [];
    }

    /**
     * 获取 session 所有数据（子类必须实现）
     *
     * @param string $id Session ID
     * @return array
     */
    abstract public function all(string $id): array;
}
