<?php
namespace Common\Metrics;

use Redis;

class Collector
{
    private static string $prefix = 'metrics:';
    private static ?Redis $redis = null;

    public static function setRedis(Redis $redis): void
    {
        self::$redis = $redis;
    }

    public static function counter(string $name, int $inc = 1, array $labels = []): void
    {
        $key = self::buildKey($name, $labels);
        self::incrby($key, $inc);
    }

    public static function gauge(string $name, float $value, array $labels = []): void
    {
        $key = self::buildKey($name, $labels);
        self::set($key, (string) $value);
    }

    public static function histogram(string $name, float $value, array $labels = []): void
    {
        $key = self::buildKey($name, $labels);
        self::append($key . ':samples', (string) $value);
    }

    public static function duration(string $name, float $startMicrotime, array $labels = []): void
    {
        $ms = round((microtime(true) - $startMicrotime) * 1000, 2);
        self::histogram($name, $ms, $labels);
    }

    public static function getAll(): array
    {
        if (!self::$redis) return [];
        $keys = self::$redis->keys(self::$prefix . '*');
        $result = [];
        foreach ($keys as $k) {
            $short = substr($k, strlen(self::$prefix));
            $val = self::$redis->get($k);
            if ($val !== false) {
                $result[$short] = $val;
            }
            // Histogram samples
            $samples = self::$redis->lRange($k . ':samples', 0, -1);
            if (!empty($samples)) {
                $result[$short . ':samples'] = $samples;
            }
        }
        return $result;
    }

    private static function buildKey(string $name, array $labels): string
    {
        $key = self::$prefix . $name;
        if (!empty($labels)) {
            $pairs = [];
            foreach ($labels as $k => $v) {
                $pairs[] = "{$k}=\"{$v}\"";
            }
            $key .= '{' . implode(',', $pairs) . '}';
        }
        return $key;
    }

    private static function incrby(string $key, int $inc): void
    {
        if (self::$redis) self::$redis->incrBy($key, $inc);
    }

    private static function set(string $key, string $value): void
    {
        if (self::$redis) self::$redis->set($key, $value);
    }

    private static function append(string $key, string $value): void
    {
        if (self::$redis) self::$redis->rPush($key, $value);
    }
}
