<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'salt'      => getenv('HASHIDS_SALT') ?: 'cloud-platform-hashids',
            'length'    => (int)(getenv('HASHIDS_LENGTH') ?: 12),
            'alphabet'  => getenv('HASHIDS_ALPHABET') ?: 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
        ],
    ],
];
