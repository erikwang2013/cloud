<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * Hashids configuration — generates short, unique, non-sequential IDs from integers.
 *
 * Hashids are reversible: encode() converts an integer to a short string,
 * decode() recovers the original integer. They are NOT cryptographically secure
 * but are useful for obfuscating auto-increment IDs in URLs and API responses.
 *
 * @see https://github.com/erikwang2013/hashids
 */
return [

    /**
     * Default connection name.
     */
    'default' => 'main',

    /**
     * Named connections. Each connection accepts:
     *  - salt:   Secret salt string (set via HASHIDS_SALT env var).
     *  - length: Minimum hash length. 0 means no minimum.
     *  - alphabet: Optional custom character set (default: a-zA-Z0-9).
     *
     * Always set a unique, random salt per connection before deploying.
     * An empty or guessable salt makes hashids trivially reversible.
     */
    'connections' => [

        'main' => [
            'salt' => env('HASHIDS_SALT', ''),
            'length' => (int) env('HASHIDS_LENGTH', 0),
        ],

        'alternative' => [
            'salt' => env('HASHIDS_ALT_SALT', 'your-alt-salt'),
            'length' => 0,
        ],

    ],

];
