<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 默认连接名，多连接时可切换
    'default' => 'main',

    'connections' => [
        // 主连接：将数据库自增/雪花 ID 混淆为短字符串，隐藏真实规模
        'main' => [
            // 盐值：确保相同的 ID 在不同项目/环境中产生不同的 hashid
            'salt'      => getenv('HASHIDS_SALT') ?: 'cloud-platform-hashids',

            // 最小输出长度：12 字符，保证 ID 外观一致
            'length'    => (int)(getenv('HASHIDS_LENGTH') ?: 12),

            // 字符集：大小写字母 + 数字，URL 友好，不含特殊字符
            'alphabet'  => getenv('HASHIDS_ALPHABET') ?: 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
        ],
    ],
];
