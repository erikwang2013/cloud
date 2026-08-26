<?php
namespace Common\feature;

use Illuminate\Support\Facades\Redis;

/**
 * Feature flag service — allows dynamic toggling of features at runtime
 * without code deploys. Flags are defined in config/features.php and can
 * be overridden via Redis for immediate effect.
 */
class FeatureFlags
{
    private const REDIS_PREFIX = 'feature:';

    /**
     * Check if a feature flag is enabled.
     * Priority: Redis override > env var > config default.
     */
    public static function isEnabled(string $flag): bool
    {
        try {
            $redisValue = Redis::get(self::REDIS_PREFIX . $flag);
            if ($redisValue !== null) {
                return $redisValue === '1';
            }
        } catch (\Throwable $e) {
            // Redis unavailable — fall through to config
        }

        $config = config("features.{$flag}");
        return (bool)$config;
    }

    /**
     * Enable a flag at runtime (Redis TTL 1h, re-read from config after expiry).
     */
    public static function enable(string $flag): void
    {
        try {
            Redis::setex(self::REDIS_PREFIX . $flag, 3600, '1');
        } catch (\Throwable $e) {
            // Redis unavailable — silent skip
        }
    }

    /**
     * Disable a flag at runtime.
     */
    public static function disable(string $flag): void
    {
        try {
            Redis::setex(self::REDIS_PREFIX . $flag, 3600, '0');
        } catch (\Throwable $e) {
            // Redis unavailable — silent skip
        }
    }

    /**
     * Reset a flag to its config default (remove Redis override).
     */
    public static function reset(string $flag): void
    {
        try {
            Redis::del(self::REDIS_PREFIX . $flag);
        } catch (\Throwable $e) {
            // Redis unavailable — silent skip
        }
    }

    /**
     * Get all configured flags with their current status.
     */
    public static function all(): array
    {
        $flags = [];
        $config = config('features') ?: [];

        foreach ($config as $name => $default) {
            $flags[$name] = [
                'enabled' => self::isEnabled($name),
                'default' => (bool)$default,
                'source'  => self::source($name),
            ];
        }

        return $flags;
    }

    private static function source(string $flag): string
    {
        try {
            if (Redis::get(self::REDIS_PREFIX . $flag) !== null) {
                return 'redis';
            }
        } catch (\Throwable $e) {
        }

        $envKey = 'FEATURE_' . strtoupper($flag);
        if (getenv($envKey) !== false) {
            return 'env';
        }

        return 'config';
    }
}
