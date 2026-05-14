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
        // CORS 跨域中间件：添加 Access-Control-* 响应头
        Common\Security\CorsMiddleware::class,

        // WAF 安全中间件：检测并拦截 SQL 注入、XSS 攻击
        Common\Security\WafMiddleware::class,

        // 多语言中间件：解析 Accept-Language，设置当前区域
        Common\I18n\Middleware\LocaleMiddleware::class,

        // Hashid 请求中间件：将请求参数中的 hashid 字符串解码为真实 ID
        Common\Hashid\Middleware\HashidRequestMiddleware::class,
    ],
];
