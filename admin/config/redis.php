<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * Redis connection configuration.
 *
 * Uses the 'default' connection. For multiple connections, add additional named
 * entries following the same structure.
 *
 * Supports: host, port, password, database index, optional prefix.
 */
return [
    // 全局 key 前缀（illuminate/redis 对全部连接生效）
    'options' => [
        'prefix' => getenv('REDIS_PREFIX') ?: 'cloud:',
    ],

    'default' => [
        /** Redis server host. */
        'host' => '127.0.0.1',
        /** Redis server password — empty string for no password. */
        'password' => null,
        /** Redis server port. */
        'port' => 6379,
        /** Redis database index (0-15 by default). */
        'database' => 0,
    ],
];
