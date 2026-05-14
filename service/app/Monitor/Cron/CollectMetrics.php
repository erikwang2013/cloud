<?php
namespace App\Monitor\Cron;

// Run every 5 minutes via crontab:
// */5 * * * * php /path/to/service/app/monitor/cron/CollectMetrics.php

require_once __DIR__ . '/../../../start.php';

$monitor = new \App\Monitor\Service\ResourceMonitor();
$monitor->collectAllMetrics();
echo "Metrics collected at " . date('Y-m-d H:i:s') . "\n";
