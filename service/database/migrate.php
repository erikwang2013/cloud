<?php
require_once __DIR__ . '/../start.php';

$files = glob(__DIR__ . '/database/migrations/*.php');
sort($files);

foreach ($files as $file) {
    echo "Running: " . basename($file) . "\n";
    require $file;
}

echo "All migrations complete.\n";
