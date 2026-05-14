<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    'key'           => getenv('ENCRYPTION_KEY') ?: '',
    'cipher'        => getenv('ENCRYPTION_CIPHER') ?: 'aes-128-ecb',
    'previous_keys' => array_filter(explode(',', getenv('ENCRYPTION_PREVIOUS_KEYS') ?: '')),
];
