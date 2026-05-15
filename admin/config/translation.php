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
 * Multilingual / i18n configuration.
 *
 * Translation files are stored as PHP arrays in the 'path' directory,
 * one file per locale (e.g., zh_CN.php, en.php).
 *
 * Use trans('key') or Locale::get('key') helper to retrieve translations.
 */
return [
    /** Default locale when no user preference is detected. */
    'locale' => 'zh_CN',

    /** Fallback locales — tried in order when a key is missing in the current locale. */
    'fallback_locale' => ['zh_CN', 'en'],

    /** Directory where locale translation files are stored. */
    'path' => base_path() . '/public/resource/translations'
];
