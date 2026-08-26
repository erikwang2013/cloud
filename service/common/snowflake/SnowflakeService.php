<?php
namespace Common\snowflake;

use Erikwang2013\Snowflake\Snowflake;

class SnowflakeService
{
    private static ?Snowflake $instance = null;

    public static function init(): Snowflake
    {
        if (self::$instance === null) {
            self::$instance = Snowflake::fromConfig([
                'worker_id'     => (int)(getenv('SNOWFLAKE_WORKER_ID') ?: 0),
                'datacenter_id' => (int)(getenv('SNOWFLAKE_DATACENTER_ID') ?: 0),
                'epoch'         => (int)(getenv('SNOWFLAKE_EPOCH') ?: 1704067200000),
            ]);
        }
        return self::$instance;
    }

    public static function nextId(): int
    {
        return self::init()->id();
    }
}
