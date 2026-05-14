<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // JWT 签名密钥（HS256 算法使用共享密钥）
    // 生成方式：openssl rand -base64 32
    'secret_key'     => getenv('JWT_SECRET_KEY') ?: '',

    // 签名算法：HS256 = HMAC-SHA256，对称密钥，性能最好
    'algorithm'      => getenv('JWT_ALGORITHM') ?: 'HS256',

    // 签发者标识，用于验证 token 的 iss 声明
    'issuer'         => getenv('JWT_ISSUER') ?: 'cloud-platform',

    // 接收者标识（可选），用于验证 token 的 aud 声明
    'audience'       => getenv('JWT_AUDIENCE') ?: '',

    // 时间容差（秒）：允许 token 已过期但仍在容差内的情况
    'leeway'         => (int)(getenv('JWT_LEEWAY') ?: 0),

    // Access Token 有效期（秒）：默认 15 分钟
    'default_expire' => (int)(getenv('JWT_ACCESS_TTL') ?: 900),

    // Refresh Token 有效期（秒）：默认 30 天
    'refresh_expire' => (int)(getenv('JWT_REFRESH_TTL') ?: 2592000),

    // Token 黑名单存储
    'storage' => [
        // 存储类型：redis（推荐），或 file
        'type'     => getenv('JWT_STORAGE_TYPE') ?: 'redis',

        // Redis key 前缀，避免与其他 key 冲突
        'prefix'   => getenv('JWT_STORAGE_PREFIX') ?: 'jwt_blacklist:',

        // Redis 数据库编号（0-15）
        'database' => (int)(getenv('JWT_STORAGE_DATABASE') ?: 0),
    ],

    // 高级配置
    'advanced' => [
        // 签名重试次数（建议保留默认）
        'retry_attempts'   => 3,

        // 重试延迟（毫秒）
        'retry_delay'      => 100,

        // 是否自动清理过期黑名单（生产环境建议手动清理）
        'auto_cleanup'     => false,

        // 自动清理间隔（秒），仅 auto_cleanup=true 时生效
        'cleanup_interval' => 3600,
    ],
];
