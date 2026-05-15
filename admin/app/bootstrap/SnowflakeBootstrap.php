<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\bootstrap;

use Snowflake\Snowflake;
use Webman\Bootstrap;
use Workerman\Worker;

/**
 * Bootstrap: registers a Snowflake singleton in the webman container on each process start.
 */
class SnowflakeBootstrap implements Bootstrap
{
    public static function start(?Worker $worker): void
    {
        $config = config('plugin.erikwang2013.snowflake-php.app');
        if (!is_array($config) || empty($config['enable'])) {
            return;
        }

        $instance = Snowflake::fromConfig($config);

        $container = \support\Container::instance();
        $container->addDefinitions([
            Snowflake::class => fn() => $instance,
            'snowflake' => fn() => $instance,
        ]);
    }
}
