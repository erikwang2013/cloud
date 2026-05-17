<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

/**
 * Poster-php configuration — captcha verification + poster generation.
 *
 * This is a custom configuration that overrides the vendor defaults.
 * Storage defaults to Redis for distributed captcha validation.
 */

return [
    // ── 图像处理驱动 ──
    'image' => [
        // 驱动类型：auto=自动检测, gd=GD库, imagick=ImageMagick
        'driver' => getenv('POSTER_IMAGE_DRIVER') ?: 'auto',
        // JPEG 输出质量 0-100
        'quality' => 90,
        // 默认字体路径，null 使用内置字体
        'font' => null,
    ],

    // ── 验证码模块 ──
    'captcha' => [
        // 存储驱动：auto=自动检测, redis=Redis（推荐）, file=文件, session=会话
        'storage' => getenv('CAPTCHA_STORAGE') ?: 'auto',

        // 验证码有效期（秒），默认 300 秒（5 分钟）
        'ttl' => (int)(getenv('CAPTCHA_TTL') ?: 300),

        // 同一 key 最多验证次数，超过则作废（防暴力枚举点击坐标）
        'max_attempts' => (int)(getenv('CAPTCHA_MAX_ATTEMPTS') ?: 3),

        // 默认难度：easy=2个目标, medium=3个, hard=4个
        'default_difficulty' => getenv('CAPTCHA_DIFFICULTY') ?: 'medium',

        // 验证误差容忍
        'tolerance' => [
            'click'  => 18,   // 点击验证像素半径
            'rotate' => 5,    // 旋转验证角度
            'slider' => 4,    // 滑块验证像素
        ],

        // Redis 存储配置（storage=redis 时生效）
        'redis' => [
            // Redis key 前缀
            'prefix'     => getenv('CAPTCHA_REDIS_PREFIX') ?: 'poster:captcha:',
            // Redis 连接名
            'connection' => 'default',
        ],

        // 文件存储配置（storage=file 时生效）
        'file' => [
            // 存储路径，null 使用系统临时目录
            'path' => null,
        ],
    ],

    // ── 海报生成模块 ──
    'poster' => [
        'default_width'  => 750,
        'default_height' => 1334,
        'font'           => null,
        'jpeg_quality'   => 90,
        'png_compression' => 6,
    ],
];
