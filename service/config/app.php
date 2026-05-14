<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    'name'              => getenv('APP_NAME') ?: 'CloudPlatform',
    'debug'             => getenv('APP_DEBUG') === 'true',
    'default_timezone'  => getenv('APP_TIMEZONE') ?: 'UTC',
    'locale'            => 'en-US',
    'fallback_locale'   => 'en-US',
    'currencies'        => ['USD', 'CNY', 'EUR', 'JPY', 'GBP'],
    'base_currency'     => 'USD',
    'error_reporting'   => E_ALL & ~E_DEPRECATED,
    'request_class'     => Webman\Http\Request::class,
];
