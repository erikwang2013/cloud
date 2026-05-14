<?php
require_once __DIR__ . '/vendor/autoload.php';

// Load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Load all configs
$configs = glob(__DIR__ . '/config/*.php');
foreach ($configs as $file) {
    $key = basename($file, '.php');
    config()->set($key, require $file);
}

// Initialize Eloquent
$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection(config('database.connections.mysql'), 'default');
$capsule->addConnection(config('database.connections.audit'), 'audit');
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Timezone
date_default_timezone_set(config('app.timezone'));

function request_id(): string {
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(8));
    }
    return $id;
}
