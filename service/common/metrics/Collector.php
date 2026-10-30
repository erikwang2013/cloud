<?php
namespace Common\metrics;

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
        $key = self::buildKey($name, $labels) . ':samples';
        self::append($key, (string) $value);
        // 只保留最近 1000 个样本，避免 list 无限增长
        if (self::$redis) {
            self::$redis->lTrim($key, -1000, -1);
        }
    }

    public static function duration(string $name, float $startMicrotime, array $labels = []): void
    {
        $ms = round((microtime(true) - $startMicrotime) * 1000, 2);
        self::histogram($name, $ms, $labels);
    }

    public static function getAll(): array
    {
        if (!self::$redis) return [];
        $result = [];
        $cursor = null;
        do {
            // SCAN 游标迭代，避免 KEYS 全键空间阻塞
            $keys = self::$redis->scan($cursor, self::$prefix . '*');
            if (!is_array($keys)) break;
            foreach ($keys as $k) {
                $short = substr($k, strlen(self::$prefix));
                // 直方图只有 :samples 列表、没有基键，必须直接从列表读取
                if (str_ends_with($k, ':samples')) {
                    $samples = self::$redis->lRange($k, 0, -1);
                    if (!empty($samples)) {
                        $result[substr($short, 0, -8) . ':samples'] = $samples;
                    }
                    continue;
                }
                $val = self::$redis->get($k);
                if ($val !== false && $val !== null) {
                    $result[$short] = $val;
                }
            }
        } while ($cursor > 0);
        return $result;
    }

    private static function buildKey(string $name, array $labels): string
    {
        $key = self::$prefix . $name;
        if (!empty($labels)) {
            $pairs = [];
            foreach ($labels as $k => $v) {
                $v = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $v);
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
