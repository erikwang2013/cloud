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

        // 忘记/重置密码：每 5 分钟 3 次（防验证码爆破与邮件轰炸）
        'password_reset' => ['rate' => 3,   'burst' => 0,  'per' => 300],

        // OAuth 流程（redirect + callback）：每 60 秒 10 次
        'oauth'    => ['rate' => 10,  'burst' => 2,  'per' => 60],

        // 验证码生成：每 60 秒 30 次
        'captcha'  => ['rate' => 30,  'burst' => 5,  'per' => 60],

        // 短信验证码：每小时 5 次（与控制器内 per-IP 限制一致）
        'sms'      => ['rate' => 5,   'burst' => 0,  'per' => 3600],

        // 支付接口限制：每 60 秒 10 次
        'pay'      => ['rate' => 10,  'burst' => 3,  'per' => 60],

        // 上传接口限制：每 60 秒 10 次
        'upload'         => ['rate' => 10,  'burst' => 2,  'per' => 60],

        // 供应商外部 API：每 60 秒 120 次
        'supplier_api'    => ['rate' => 120, 'burst' => 20, 'per' => 60],

        // 供应商提现：每 60 秒 10 次
        'supplier_withdraw' => ['rate' => 10,  'burst' => 3,  'per' => 60],

        // GraphQL API：每 60 秒 30 次（查询复杂度限制 100）
        'graphql'          => ['rate' => 30,  'burst' => 5,  'per' => 60],

        // 内部健康检查端点（高频率，token/loopback 保护）
        'health'           => ['rate' => 120, 'burst' => 20, 'per' => 60],
    ],

    // WAF 规则：正则匹配，拦截常见 Web/API 攻击模式
    'waf' => [
        // SQL 注入特征检测
        'sqli_patterns' => [
            // 特殊字符与注释符
            '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',
            // SQL 关键字（需配合 SQL 结构出现，避免普通文本误伤：create/select/update 等单词在日常工单/评价中常见）
            '/\b(union)\s+(all\s+)?select\b/i',
            '/\bselect\b.{0,60}\bfrom\b/i',
            '/\b(insert\s+into|delete\s+from|drop\s+table|truncate\s+table|update\s+\w+\s+set)\b/i',
            // 十六进制编码注入：0xDEADBEEF
            '/\b0x[0-9a-fA-F]{4,}\b/',
            // 联合查询变形：UNION ALL SELECT, UNION/**/SELECT
            '/(\%55\%4e\%49\%4f\%4e|union).*(select)/si',
            // 永真条件注入：' OR '1'='1, OR 1=1, %27+OR+1%3D1
            '/([\'\"\%])\s*or\s*[\'\"\%]?\s*[0-9a-z]+\s*[\'\"\%]?\s*=\s*[\'\"\%]?\s*[0-9a-z]+/i',
            // 时间盲注：sleep(, benchmark(, WAITFOR DELAY
            '/\b(sleep|benchmark|pg_sleep)\s*\(/i',
            '/\bWAITFOR\s+DELAY\b/i',
            // 堆叠查询
            '/;\s*\b(drop|insert|update|delete|select|exec)\b/i',
            // 多行注释绕过
            '/\/\*!|\*\/|\/\*\*\/|\bSELECT\b.*\/\*\*\//i',
        ],

        // XSS 跨站脚本特征检测
        'xss_patterns' => [
            // HTML 标签（含编码变形）
            '/((\%3C)|<)((\%2F)|\/)*[a-z0-9\%]+((\%3E)|>)/i',
            // Script 标签及变体（大小写、空格、编码）
            '/<\s*s\s*c\s*r\s*i\s*p\s*t[\s>\/]/i',
            '/<script[\s>\/]/i',
            // JS 事件处理器
            '/\b(onload|onerror|onclick|onmouseover|onmouseout|onfocus|onblur|onkeypress|onkeydown|onkeyup|onsubmit|onchange|oninput|ondblclick|oncontextmenu|onanimationend)\b/i',
            // JS 全局对象与危险函数
            '/\b(document\.|window\.|alert|eval|setTimeout|setInterval|Function\(|constructor)\b/i',
            // JavaScript 伪协议
            '/javascript\s*:/i',
            // HTML 实体编码绕过：&#x3C;, &#60;
            '/&#x?[0-9a-fA-F]+/i',
            // Data URI 在属性中
            '/data\s*:\s*text\/html/i',
            // 内联事件属性
            '/\bon[a-z]+\s*=\s*[\"\'][^\"\']*\([^\"\']*\)/i',
        ],

        // 命令注入特征检测
        'cmd_injection_patterns' => [
            // 管道符后跟命令
            '/\|\s*\b(cat|ls|rm|wget|curl|nc|bash|sh|cmd|powershell|whoami|id)\b/i',
            // 分号后跟命令
            '/;\s*\b(cat|ls|rm|wget|curl|nc|bash|sh|cmd|powershell|whoami|id|uname|ifconfig|ipconfig|nslookup|ping)\b/i',
            // 命令替换：$(cmd), `cmd`
            '/\$\([^)]+\)/',
            '/`[^`]+`/',
            // 常见系统命令（含参数）
            '/\b(cat|ls|rm|wget|curl|nc|netcat|bash|sh|zsh|cmd|powershell|whoami|id|uname|ifconfig|ipconfig|nslookup|ping|tracert)\s+/i',
        ],

        // 文件包含 / 路径穿越特征检测
        'file_inclusion_patterns' => [
            // 路径穿越（多种编码）
            '/\.\.\/|\.\.\%2f|\.\.\\\\|\.\.\%5c|\.\.\/\.\.\//i',
            // PHP 伪协议
            '/\b(php|file|glob|data|expect|phar|zip|ogg):\/\//i',
            // 绝对路径探测
            '/(\/etc\/|\/proc\/|\/var\/|\/tmp\/|C:\\\\|%SYSTEMROOT%)/i',
            // Null byte 注入
            '/\%00|\\x00/',
        ],

        // HTTP 头注入 / CRLF 攻击
        'header_injection_patterns' => [
            // CRLF 换行注入
            '/\%0[ad]|\\r\\n|\\r|\\n/i',
            // Host 头攻击
            '/\n\s*(Host|Cookie|Set-Cookie|Location|Content-Type):/i',
        ],

        // SSRF 服务端请求伪造检测
        'ssrf_patterns' => [
            // 内网 IPv4 地址（127.x, 10.x, 172.16-31.x, 192.168.x）
            '/\b(127\.\d{1,3}\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/',
            '/\b172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}\b/',
            '/\b192\.168\.\d{1,3}\.\d{1,3}\b/',
            // localhost 别名
            '/\b(localhost|0\.0\.0\.0|0x7f000001)\b/i',
            // 云 metadata 端点
            '/\b169\.254\.169\.254\b/',
            // file:// 协议
            '/\bfile:\/\/\/?\b/i',
        ],

        // NoSQL 注入检测（MongoDB/Redis 操作符）
        'nosql_injection_patterns' => [
            // MongoDB 操作符注入（$where, $gt, $regex 等）
            '/(\$where|\$gt|\$gte|\$lt|\$lte|\$ne|\$nin|\$in|\$regex|\$exists|\$or|\$and|\$nor|\$not|\$eq)\b/i',
            // MongoDB $where JS 注入
            '/\$where\s*[=:]\s*[\"\'{]?\s*\$?(function|eval|while|for|require)/i',
            // Redis 危险命令注入
            '/\b(FLUSHALL|FLUSHDB|CONFIG\s+SET|CONFIG\s+REWRITE|SHUTDOWN|DEBUG\s+SEGFAULT|SLAVEOF|REPLICAOF)\b/i',
        ],

        // 开放重定向检测
        'open_redirect_patterns' => [
            // 外部 URL 在 redirect/return_to/next 参数中
            '/(redirect_uri|redirect_url|return_url|return_to|next|callback)["\']?\s*[=:]\s*["\']?\s*https?:\/\/(?![\w\-\.]*example\.com)/i',
            // // 双重编码的协议前缀
            '/\%2[fF]{2}\%2[fF]{2}|%25%32%[fF]|\\\\x2[fF]|data\s*:\s*text\/html/i',
        ],
    ],

    // 请求限制
    'request_limits' => [
        // 请求体最大 10MB
        'max_body_size' => 10 * 1024 * 1024,
        // URL 最大 2KB（防 ReDoS + 缓冲区溢出）
        'max_url_length' => 2048,
        // 允许的 Content-Type（仅 API 路由校验）
        'allowed_content_types' => [
            'application/json',
            'multipart/form-data',
            'application/x-www-form-urlencoded',
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
