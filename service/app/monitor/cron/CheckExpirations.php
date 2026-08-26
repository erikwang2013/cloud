<?php
namespace App\monitor\cron;

use App\monitor\service\ResourceMonitor;

// Run every hour via crontab:
// 7 * * * * php /path/to/service/app/monitor/cron/CheckExpirations.php

require_once __DIR__ . '/../../../start.php';

$monitor = new ResourceMonitor();
$monitor->checkExpirations();
$monitor->checkSslCertificates();
echo "Expiration checks completed at " . date('Y-m-d H:i:s') . "\n";
