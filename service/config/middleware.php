<?php
return [
    '' => [
        \Common\Security\CorsMiddleware::class,
        \Common\Security\WafMiddleware::class,
        \Common\I18n\Middleware\LocaleMiddleware::class,
    ],
];
