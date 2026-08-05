<?php
namespace support;

use Illuminate\Redis\RedisManager;

/**
 * Redis 门面（webman 骨架标准入口）。
 *
 * 本文件属于 webman 应用骨架的 support 层，但本项目 composer 只依赖
 * webman-framework（不含 webman/webman 骨架），导致 support\Redis 缺失。
 * 底层使用项目已有的 illuminate/redis（composer: illuminate/redis），
 * 配置来源为 config/redis.php。
 */
class Redis
{
    protected static ?RedisManager $manager = null;

    public static function manager(): RedisManager
    {
        if (static::$manager === null) {
            $config = config('redis', []);
            static::$manager = new RedisManager('default', 'phpredis', $config);
        }
        return static::$manager;
    }

    public static function connection(string $name = 'default')
    {
        return static::manager()->connection($name);
    }

    public static function __callStatic(string $name, array $arguments)
    {
        return static::connection()->{$name}(...$arguments);
    }
}
