<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    'password' => [
        'algo'  => PASSWORD_BCRYPT,
        'cost'  => 12,
        'min_length' => 8,
    ],
    'mfa' => [
        'issuer' => 'CloudPlatform',
        'digits' => 6,
        'period' => 30,
        'algo'   => 'sha1',
    ],
];
