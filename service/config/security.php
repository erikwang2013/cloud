<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 速率限制：防止暴力破解、刷接口、DoS 攻击
    'rate_limits' => [
        // 默认限制：每 60 秒允许 60 次，突发 +10
        'default'  => ['rate' => 60,  'burst' => 10, 'per' => 60],

        // 登录接口限制：每 60 秒仅 5 次（防撞库）
        'login'    => ['rate' => 5,   'burst' => 2,  'per' => 60],

        // 注册接口限制：每 60 秒仅 3 次（防批量注册）
        'register' => ['rate' => 3,   'burst' => 0,  'per' => 60],

        // 支付接口限制：每 60 秒 10 次
        'pay'      => ['rate' => 10,  'burst' => 3,  'per' => 60],

        // 上传接口限制：每 60 秒 10 次
        'upload'   => ['rate' => 10,  'burst' => 2,  'per' => 60],
    ],

    // WAF 规则：简单正则匹配，拦截常见攻击模式
    'waf' => [
        // SQL 注入特征检测
        'sqli_patterns' => [
            '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',                                        // 单引号、注释符
            '/\b(union|select|insert|update|delete|drop|alter|create|truncate)\b/i',   // SQL 关键字
        ],

        // XSS 跨站脚本特征检测
        'xss_patterns' => [
            '/((\%3C)|<)((\%2F)|\/)*[a-z0-9\%]+((\%3E)|>)/i',                          // HTML 标签
            '/\b(onload|onerror|onclick|document\.|window\.|alert|eval)\b/i',           // JS 事件/函数
        ],
    ],

    // 传输加密：API 请求/响应体 AES-256-GCM 加密
    'encryption' => [
        // GCM 模式提供认证加密（防篡改），不同于 encryptable 的 ECB 模式
        'algo'   => 'aes-256-gcm',

        // 传输加密主密钥，与 encryptable 的 ENCRYPTION_KEY 分开管理
        'master_key' => getenv('ENCRYPTION_MASTER_KEY'),
    ],
];
