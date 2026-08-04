<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 全局中间件（空字符串 key = 对所有路由生效）
    // 执行顺序：从上到下依次执行
    '' => [
        // API 版本中间件：从 X-Api-Version 头读取版本，校验并注入
        Common\Version\Middleware\VersionMiddleware::class,

        // CORS 跨域中间件：添加 Access-Control-* 响应头
        Common\Security\CorsMiddleware::class,

        // 安全响应头中间件：HSTS / X-Frame-Options / CSP / Referrer-Policy 等
        Common\Security\SecurityHeadersMiddleware::class,

        // 客户端平台中间件：从 X-Client-Platform 头识别客户端操作系统平台
        Common\ClientPlatform\Middleware\ClientPlatformMiddleware::class,

        // 地理封禁中间件：根据 GEO_BLOCKED_COUNTRIES 阻止指定国家的访问
        Common\Security\GeoBlockMiddleware::class,

        // WAF 安全中间件：检测并拦截 SQL 注入、XSS 攻击（传统，保留兼容）
        Common\Security\WafMiddleware::class,

        // Security Plugin — 31 种攻击检测（XSS/SQL注入/命令注入/SSRF/反序列化等）
        Erikwang2013\Security\Middleware\Webman\SecurityMiddleware::class,

        // 多语言中间件：解析 Accept-Language，设置当前区域
        Common\I18n\Middleware\LocaleMiddleware::class,

        // Hashid 请求中间件：将请求参数中的 hashid 字符串解码为真实 ID
        Common\Hashid\Middleware\HashidRequestMiddleware::class,

        // 维护模式中间件：MAINTENANCE_MODE=true 时仅允许白名单 IP
        Common\Security\MaintenanceMiddleware::class,
    ],
];
