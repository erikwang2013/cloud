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

use support\view\Raw;
use support\view\Twig;
use support\view\Blade;
use support\view\ThinkPHP;

/**
 * View / template engine configuration.
 *
 * 'Raw' uses native PHP templates (recommended for Layui-based admin).
 * Other options: Twig, Blade (Laravel), ThinkPHP template engine.
 * Switch the 'handler' below to change the template engine.
 */
return [
    /** Template engine handler. */
    'handler' => Raw::class,

    /** View file extension. */
    'view_suffix' => 'html',

    /** Additional options passed to the view engine constructor. */
    'options' => [
        'view_path' => base_path() . '/app/view/',
    ],
];
