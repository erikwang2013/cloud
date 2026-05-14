<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    '' => [
        Common\Security\CorsMiddleware::class,
        Common\Security\WafMiddleware::class,
        Common\I18n\Middleware\LocaleMiddleware::class,
        Common\Hashid\Middleware\HashidRequestMiddleware::class,
    ],
];
