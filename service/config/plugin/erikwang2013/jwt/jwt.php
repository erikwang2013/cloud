<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    'secret_key'     => getenv('JWT_SECRET_KEY') ?: '',
    'algorithm'      => getenv('JWT_ALGORITHM') ?: 'HS256',
    'issuer'         => getenv('JWT_ISSUER') ?: 'cloud-platform',
    'audience'       => getenv('JWT_AUDIENCE') ?: '',
    'leeway'         => (int)(getenv('JWT_LEEWAY') ?: 0),
    'default_expire' => (int)(getenv('JWT_ACCESS_TTL') ?: 900),
    'refresh_expire' => (int)(getenv('JWT_REFRESH_TTL') ?: 2592000),
    'storage' => [
        'type'     => getenv('JWT_STORAGE_TYPE') ?: 'redis',
        'prefix'   => getenv('JWT_STORAGE_PREFIX') ?: 'jwt_blacklist:',
        'database' => (int)(getenv('JWT_STORAGE_DATABASE') ?: 0),
    ],
    'advanced' => [
        'retry_attempts'   => 3,
        'retry_delay'      => 100,
        'auto_cleanup'     => false,
        'cleanup_interval' => 3600,
    ],
];
