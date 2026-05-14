<?php
return [
    'name'      => getenv('APP_NAME') ?: 'CloudPlatform',
    'debug'     => getenv('APP_DEBUG') === 'true',
    'timezone'  => 'UTC',
    'locale'    => 'en-US',
    'fallback_locale' => 'en-US',
    'currencies' => ['USD', 'CNY', 'EUR', 'JPY', 'GBP'],
    'base_currency' => 'USD',
];
