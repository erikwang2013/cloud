<?php
namespace Common\Hashid;

use Erikwang2013\Hashids\HashidsFactory;
use Erikwang2013\Hashids\HashidsManager;
use Hashids\Hashids;

class HashidService
{
    private static ?HashidsManager $manager = null;

    public static function init(): HashidsManager
    {
        if (self::$manager === null) {
            $config = config('hashids');
            self::$manager = new HashidsManager($config, new HashidsFactory());
        }
        return self::$manager;
    }

    public static function connection(?string $name = null): Hashids
    {
        $manager = self::init();
        if ($name) {
            return $manager->connection($name);
        }
        $container = \support\Container::instance();
        if ($container) {
            return $container->get(Hashids::class);
        }
        return $manager->connection();
    }

    public static function encode(int $id): string
    {
        return self::connection()->encode($id);
    }

    public static function decode(string $hash): ?int
    {
        $ids = self::connection()->decode($hash);
        if (empty($ids)) {
            return null;
        }
        return (int)$ids[0];
    }

    /**
     * Recursively transform id fields in data to hashids.
     */
    public static function encodeIds($data): array
    {
        if ($data === null) {
            return [];
        }
        $data = self::toArray($data);

        return self::walk($data);
    }

    private static function walk(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($key === 'id' && is_int($value)) {
                $data[$key] = self::encode($value);
            } elseif (is_array($value)) {
                $data[$key] = self::walk($value);
            }
        }
        return $data;
    }

    private static function toArray($data): array
    {
        if (is_array($data)) {
            return $data;
        }
        if (is_object($data) && method_exists($data, 'toArray')) {
            return $data->toArray();
        }
        return json_decode(json_encode($data), true);
    }
}
