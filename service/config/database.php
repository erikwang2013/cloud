<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 默认连接，不指定连接时使用
    'default' => 'mysql',

    'connections' => [
        // 主业务数据库（读写分离）：写操作走 master，读操作走 replica
        'mysql' => [
            'driver'    => 'mysql',

            // 读写分离 — 写库（主库）
            'write' => [
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
            ],

            // 读写分离 — 读库（从库，可配置多个实现负载均衡）
            'read' => [
                [
                    'host' => getenv('DB_READ_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1',
                ],
            ],

            'port'      => getenv('DB_PORT') ?: '3306',
            'database'  => getenv('DB_DATABASE') ?: 'cloud_platform',
            'username'  => getenv('DB_USERNAME') ?: 'app_user',
            'password'  => getenv('DB_PASSWORD') ?: '',

            // Eloquent 自动将 SELECT 查询路由到 read 连接，INSERT/UPDATE/DELETE 路由到 write
            'sticky'    => true,  // 同一请求周期内写后读走主库（防止主从延迟）

            // utf8mb4 支持 emoji 和全部 Unicode 字符
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',

            // 表前缀
            'prefix'    => '',

            // strict=true 时 MySQL 严格模式，防止数据截断和隐式类型转换
            'strict'    => true,

            // 连接池选项（webman 常驻进程下每个 worker 维持长连接）
            'options'   => [
                PDO::ATTR_PERSISTENT         => true,  // 持久连接
                PDO::ATTR_EMULATE_PREPARES   => false, // 使用原生 prepared statement
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ],
        ],

        // 审计数据库：独立存储敏感操作日志，满足合规和安全审计要求
        'audit' => [
            'driver'    => 'mysql',
            'host'      => getenv('AUDIT_DB_HOST') ?: '127.0.0.1',
            'port'      => getenv('AUDIT_DB_PORT') ?: '3306',
            'database'  => getenv('AUDIT_DB_DATABASE') ?: 'cloud_platform_audit',
            'username'  => getenv('AUDIT_DB_USERNAME') ?: 'app_user',
            'password'  => getenv('AUDIT_DB_PASSWORD') ?: '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
];
