<?php

namespace App\Grpc;

/**
 * In-process cache of discovered peers, filled by RegistryProcess timer.
 */
class RegistryStatus
{
    private static array $instances = [];

    public static function set(string $name, array $instances): void
    {
        self::$instances[$name] = $instances;
    }

    /**
     * @return array{name:string,version:string,endpoints:array,metadata:array}|null
     */
    public static function first(string $name): ?array
    {
        return self::$instances[$name][0] ?? null;
    }

    public static function all(): array
    {
        return self::$instances;
    }
}
