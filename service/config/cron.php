<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

/**
 * Scheduled tasks configuration.
 *
 * Each entry defines a cron-like schedule. Tasks are executed by the
 * webman cron worker process. Format:
 *
 *   'schedule' => [ControllerClass, 'method']
 *
 * schedule uses standard 5-field cron syntax: min hour day month weekday
 */
return [
    // Exchange rate sync — every 4 hours at :13
    '13 */4 * * *' => [App\cron\ExchangeRateSync::class, 'run'],

    // Payment reconciliation — daily at 02:37
    '37 2 * * *'   => [App\cron\PaymentReconcile::class, 'run'],

    // Supplier settlement — weekly on Monday at 04:17
    '17 4 * * 1'   => [App\cron\SupplierSettlement::class, 'run'],

    // Domain/Renewal expiry check — daily at 06:23
    '23 6 * * *'   => [App\cron\ExpirationCheck::class, 'run'],

    // SSL certificate check — twice daily at 07:43 and 19:43
    '43 7,19 * * *' => [App\cron\SslCertificateCheck::class, 'run'],

    // Resource metrics collection — every 5 minutes
    '*/5 * * * *'  => [App\monitor\service\ResourceMonitor::class, 'collectAllMetrics'],

    // Online SSL certificate probe for domain resources — every 30 minutes
    // (expiration notifications covered by ExpirationCheck + SslCertificateCheck)
    '*/30 * * * *' => [App\monitor\service\ResourceMonitor::class, 'checkSslCertificates'],

    // Usage aggregation — hourly at :07
    '7 * * * *'    => [App\billing\service\UsageAggregator::class, 'aggregate'],

    // Usage billing — daily at 03:41
    '41 3 * * *'   => [App\billing\service\BillingEngine::class, 'runDaily'],

    // Suspend check — every 30 minutes at :11 and :41
    '11,41 * * * *' => [App\billing\cron\SuspendCheck::class, 'run'],
];
