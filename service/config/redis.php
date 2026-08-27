<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 全局 key 前缀（illuminate/redis 对全部连接生效）
    'options' => [
        'prefix' => getenv('REDIS_PREFIX') ?: 'cloud:',
    ],

    // 默认 Redis 连接：JWT 黑名单、会话、队列等通用用途
    'default' => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => getenv('REDIS_PORT') ?: '6379',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => 0,  // db0：通用
    ],

    // 缓存专用连接：存储查询结果缓存、配置缓存等
    'cache' => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => getenv('REDIS_PORT') ?: '6379',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => 1,  // db1：缓存数据，可独立 flush
    ],

    // 会话专用连接：存储用户登录态
    'session' => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => getenv('REDIS_PORT') ?: '6379',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => 2,  // db2：会话数据
    ],
];
