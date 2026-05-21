<?php
namespace Common\Helper;

use Illuminate\Support\Facades\Redis;

/**
 * Multi-level caching service for high-frequency read data.
 *
 * Usage:
 *   $products = CacheService::remember('products:list:'.$regionId, 300, fn() => Product::where(...)->get());
 */
class CacheService
{
    // Cache TTL constants (seconds)
    public const TTL_PRODUCT_LIST   = 300;   // 5 min — product listings
    public const TTL_PRODUCT_DETAIL = 600;   // 10 min — product detail pages
    public const TTL_REGIONS        = 3600;  // 1 hour — regions rarely change
    public const TTL_EXCHANGE_RATES = 1800;  // 30 min — exchange rates
    public const TTL_TLDS           = 3600;  // 1 hour — TLD pricing
    public const TTL_USER_PROFILE   = 120;   // 2 min — user profile (short, user-editable)
    public const TTL_HELP_ARTICLES  = 600;   // 10 min — help articles
    public const TTL_CATEGORIES     = 600;   // 10 min — product categories

    // Cache key prefix to avoid collisions
    private const PREFIX = 'cache:';

    /**
     * Get from cache or compute, store, and return.
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        $fullKey = self::PREFIX . $key;
        try {
            $cached = Redis::get($fullKey);
            if ($cached !== null) {
                return json_decode($cached, true);
            }
        } catch (\Throwable $e) {
            // Redis unavailable — fall through to callback
        }

        $data = $callback();
        self::put($key, $data, $ttl);
        return $data;
    }

    /**
     * Store value in cache.
     */
    public static function put(string $key, mixed $value, int $ttl): void
    {
        try {
            Redis::setex(self::PREFIX . $key, $ttl, json_encode($value));
        } catch (\Throwable $e) {
            // Redis unavailable — silent skip
        }
    }

    /**
     * Invalidate a single cache key.
     */
    public static function forget(string $key): void
    {
        try {
            Redis::del(self::PREFIX . $key);
        } catch (\Throwable $e) {
            // Redis unavailable — silent skip
        }
    }

    /**
     * Invalidate all keys matching a pattern (e.g. "products:*").
     */
    public static function forgetPattern(string $pattern): void
    {
        try {
            $keys = Redis::keys(self::PREFIX . $pattern);
            if (!empty($keys)) {
                Redis::del($keys);
            }
        } catch (\Throwable $e) {
            // Redis unavailable — silent skip
        }
    }

    /**
     * Warm up cache by pre-computing keys. Useful during deployment.
     */
    public static function warmUp(array $keys): void
    {
        foreach ($keys as $key => $config) {
            self::remember($key, $config['ttl'], $config['callback']);
        }
    }
}
