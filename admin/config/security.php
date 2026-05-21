<?php

return [
    'waf' => [
        // SQL 注入特征检测
        'sqli_patterns' => [
            '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',
            '/\b(union|select|insert|update|delete|drop|alter|create|truncate|exec|execute|grant|revoke)\b/i',
            '/\b0x[0-9a-fA-F]{4,}\b/',
            '/(\%55\%4e\%49\%4f\%4e|union).*(select)/si',
            '/([\'\"\%])\s*or\s*[\'\"\%]?\s*[0-9a-z]+\s*[\'\"\%]?\s*=\s*[\'\"\%]?\s*[0-9a-z]+/i',
            '/\b(sleep|benchmark|pg_sleep)\s*\(/i',
            '/\bWAITFOR\s+DELAY\b/i',
            '/;\s*\b(drop|insert|update|delete|select|exec)\b/i',
            '/\/\*!|\*\/|\/\*\*\/|\bSELECT\b.*\/\*\*\//i',
        ],

        // XSS 跨站脚本特征检测
        'xss_patterns' => [
            '/((\%3C)|<)((\%2F)|\/)*[a-z0-9\%]+((\%3E)|>)/i',
            '/<\s*s\s*c\s*r\s*i\s*p\s*t[\s>\/]/i',
            '/<script[\s>\/]/i',
            '/\b(onload|onerror|onclick|onmouseover|onmouseout|onfocus|onblur|onkeypress|onkeydown|onkeyup|onsubmit|onchange|oninput|ondblclick|oncontextmenu|onanimationend)\b/i',
            '/\b(document\.|window\.|alert|eval|setTimeout|setInterval|Function\(|constructor)\b/i',
            '/javascript\s*:/i',
            '/&#x?[0-9a-fA-F]+/i',
            '/data\s*:\s*text\/html/i',
            '/\bon[a-z]+\s*=\s*[\"\'][^\"\']*\([^\"\']*\)/i',
        ],

        // 命令注入特征检测
        'cmd_injection_patterns' => [
            '/\|\s*\b(cat|ls|rm|wget|curl|nc|bash|sh|cmd|powershell|whoami|id)\b/i',
            '/;\s*\b(cat|ls|rm|wget|curl|nc|bash|sh|cmd|powershell|whoami|id|uname|ifconfig|ipconfig|nslookup|ping)\b/i',
            '/\$\([^)]+\)/',
            '/`[^`]+`/',
            '/\b(cat|ls|rm|wget|curl|nc|netcat|bash|sh|zsh|cmd|powershell|whoami|id|uname|ifconfig|ipconfig|nslookup|ping|tracert)\s+/i',
        ],

        // 文件包含 / 路径穿越
        'file_inclusion_patterns' => [
            '/\.\.\/|\.\.\%2f|\.\.\\\\|\.\.\%5c|\.\.\/\.\.\//i',
            '/\b(php|file|glob|data|expect|phar|zip|ogg):\/\//i',
            '/(\/etc\/|\/proc\/|\/var\/|\/tmp\/|C:\\\\|%SYSTEMROOT%)/i',
            '/\%00|\\x00/',
        ],

        // HTTP 头注入
        'header_injection_patterns' => [
            '/\%0[ad]|\\r\\n|\\r|\\n/i',
            '/\n\s*(Host|Cookie|Set-Cookie|Location|Content-Type):/i',
        ],

        // SSRF 服务端请求伪造检测
        'ssrf_patterns' => [
            '/\b(127\.\d{1,3}\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/',
            '/\b172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}\b/',
            '/\b192\.168\.\d{1,3}\.\d{1,3}\b/',
            '/\b(localhost|0\.0\.0\.0|0x7f000001)\b/i',
            '/\b169\.254\.169\.254\b/',
            '/\bfile:\/\/\/?\b/i',
        ],

        // NoSQL 注入检测
        'nosql_injection_patterns' => [
            '/(\$where|\$gt|\$gte|\$lt|\$lte|\$ne|\$nin|\$in|\$regex|\$exists|\$or|\$and|\$nor|\$not|\$eq)\b/i',
            '/\$where\s*[=:]\s*[\"\'{]?\s*\$?(function|eval|while|for|require)/i',
            '/\b(FLUSHALL|FLUSHDB|CONFIG\s+SET|CONFIG\s+REWRITE|SHUTDOWN|DEBUG\s+SEGFAULT|SLAVEOF|REPLICAOF)\b/i',
        ],

        // 开放重定向检测
        'open_redirect_patterns' => [
            '/(redirect_uri|redirect_url|return_url|return_to|next|callback)["\']?\s*[=:]\s*["\']?\s*https?:\/\/(?![\w\-\.]*example\.com)/i',
            '/\%2[fF]{2}\%2[fF]{2}|%25%32%[fF]|\\\\x2[fF]|data\s*:\s*text\/html/i',
        ],
    ],

    // 请求限制
    'request_limits' => [
        'max_body_size' => 10 * 1024 * 1024,
        'max_url_length' => 2048,
        'allowed_content_types' => [
            'application/json',
            'multipart/form-data',
            'application/x-www-form-urlencoded',
        ],
    ],
];
