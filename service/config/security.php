<?php
return [
    'rate_limits' => [
        'default'  => ['rate' => 60,  'burst' => 10, 'per' => 60],
        'login'    => ['rate' => 5,   'burst' => 2,  'per' => 60],
        'register' => ['rate' => 3,   'burst' => 0,  'per' => 60],
        'pay'      => ['rate' => 10,  'burst' => 3,  'per' => 60],
        'upload'   => ['rate' => 10,  'burst' => 2,  'per' => 60],
    ],
    'waf' => [
        'sqli_patterns' => [
            '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',
            '/\b(union|select|insert|update|delete|drop|alter|create|truncate)\b/i',
        ],
        'xss_patterns' => [
            '/((\%3C)|<)((\%2F)|\/)*[a-z0-9\%]+((\%3E)|>)/i',
            '/\b(onload|onerror|onclick|document\.|window\.|alert|eval)\b/i',
        ],
    ],
    'encryption' => [
        'algo'   => 'aes-256-gcm',
        'master_key' => getenv('ENCRYPTION_MASTER_KEY'),
    ],
];
