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
 * Application configuration.
 */
return [
    /** Enable/disable debug mode. Disable in production. */
    'debug' => true,

    /** Controller class name suffix, e.g. "Controller" for class IndexController. */
    'controller_suffix' => 'Controller',

    /** Reuse controller instances across requests (singleton). false = fresh instance per request. */
    'controller_reuse' => false,

    /** Public document root — static assets (JS, CSS, images) served from here. */
    'public_path' => base_path() . '/public',

    /** Remote plugin marketplace host for PluginController. */
    'plugin_market_host' => 'https://www.workerman.net',

    /** Admin panel version. */
    'version' => '0.6.33',
];
