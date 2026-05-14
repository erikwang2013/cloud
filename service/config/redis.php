<?php
return [
    'default' => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => getenv('REDIS_PORT') ?: '6379',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => 0,
    ],
    'cache' => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => getenv('REDIS_PORT') ?: '6379',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => 1,
    ],
    'session' => [
        'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port'     => getenv('REDIS_PORT') ?: '6379',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => 2,
    ],
];
