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
        // 主业务数据库：用户、订单、产品、支付等所有业务数据
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => getenv('DB_HOST') ?: '127.0.0.1',
            'port'      => getenv('DB_PORT') ?: '3306',
            'database'  => getenv('DB_DATABASE') ?: 'cloud_platform',
            'username'  => getenv('DB_USERNAME') ?: 'app_user',
            'password'  => getenv('DB_PASSWORD') ?: '',

            // utf8mb4 支持 emoji 和全部 Unicode 字符
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',

            // 表前缀，通过 env 配置即可，此处补充说明
            'prefix'    => '',

            // strict=true 时 MySQL 严格模式，防止数据截断和隐式类型转换
            'strict'    => true,
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
