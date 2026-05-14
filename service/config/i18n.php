<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 默认语言区域（美式英语）
    'default_locale'   => 'en-US',

    // 回退语言区域，当翻译 key 在当前区域缺失时的兜底
    'fallback_locale'  => 'en-US',

    // 平台支持的所有语言区域，用于语言切换菜单和 Accept-Language 匹配
    'supported_locales' => ['en-US', 'zh-CN', 'ja-JP', 'ko-KR', 'de-DE', 'fr-FR', 'es-ES'],

    // 短语言代码 → 完整区域代码的映射
    // Accept-Language 请求头通常只传 "zh" 而非 "zh-CN"
    'locale_map' => [
        'en' => 'en-US', 'zh' => 'zh-CN', 'ja' => 'ja-JP',
        'ko' => 'ko-KR', 'de' => 'de-DE', 'fr' => 'fr-FR', 'es' => 'es-ES',
    ],
];
