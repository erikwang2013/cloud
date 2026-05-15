<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * Static file serving configuration.
 *
 * Webman can serve static assets (JS, CSS, images) directly from the public/
 * directory. For production, use nginx/apache to serve static files instead.
 */
return [
    /** Enable static file serving within webman. Set false in production with reverse proxy. */
    'enable' => true,

    /** Additional middleware for static file requests (empty = no extra middleware). */
    'middleware' => [],
];
