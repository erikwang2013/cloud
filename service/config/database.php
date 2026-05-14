<?php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => getenv('DB_HOST') ?: '127.0.0.1',
            'port'      => getenv('DB_PORT') ?: '3306',
            'database'  => getenv('DB_DATABASE') ?: 'cloud_platform',
            'username'  => getenv('DB_USERNAME') ?: 'app_user',
            'password'  => getenv('DB_PASSWORD') ?: '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
        ],
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
