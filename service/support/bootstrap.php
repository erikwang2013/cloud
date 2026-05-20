<?php
// Bootstrap — loaded by webman on worker start
// Based on vendor/workerman/webman-framework/src/support/bootstrap.php

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use support\Log;
use Webman\Bootstrap;
use Webman\Config;
use Webman\Middleware;
use Webman\Route;
use Webman\Util;
use Workerman\Events\Select;
use Workerman\Worker;

$worker = $worker ?? null;

if (empty(Worker::$eventLoopClass)) {
    Worker::$eventLoopClass = Select::class;
}

set_error_handler(function ($level, $message, $file = '', $line = 0) {
    if (error_reporting() & $level) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }
});

if ($worker) {
    register_shutdown_function(function ($startTime) {
        if (time() - $startTime <= 0.1) {
            sleep(1);
        }
    }, time());
}

if (class_exists(Dotenv::class) && file_exists(base_path(false) . '/.env')) {
    if (method_exists(Dotenv::class, 'createUnsafeMutable')) {
        Dotenv::createUnsafeMutable(base_path(false))->load();
    } else {
        Dotenv::createMutable(base_path(false))->load();
    }
}

Config::clear();
support\App::loadAllConfig(['route']);
if ($timezone = config('app.default_timezone')) {
    date_default_timezone_set($timezone);
}

foreach (config('autoload.files', []) as $file) {
    include_once $file;
}
foreach (config('plugin', []) as $firm => $projects) {
    foreach ($projects as $name => $project) {
        if (!is_array($project)) {
            continue;
        }
        foreach ($project['autoload']['files'] ?? [] as $file) {
            include_once $file;
        }
    }
    foreach ($projects['autoload']['files'] ?? [] as $file) {
        include_once $file;
    }
}

Middleware::load(config('middleware', []));
foreach (config('plugin', []) as $firm => $projects) {
    foreach ($projects as $name => $project) {
        if (!is_array($project) || $name === 'static') {
            continue;
        }
        Middleware::load($project['middleware'] ?? []);
    }
    Middleware::load($projects['middleware'] ?? [], $firm);
    if ($staticMiddlewares = config("plugin.$firm.static.middleware")) {
        Middleware::load(['__static__' => $staticMiddlewares], $firm);
    }
}
Middleware::load(['__static__' => config('static.middleware', [])]);

foreach (config('bootstrap', []) as $className) {
    if (!class_exists($className)) {
        $log = "Warning: Class $className setting in config/bootstrap.php not found\r\n";
        echo $log;
        Log::error($log);
        continue;
    }
    /** @var Bootstrap $className */
    $className::start($worker);
}

foreach (config('plugin', []) as $firm => $projects) {
    foreach ($projects as $name => $project) {
        if (!is_array($project)) {
            continue;
        }
        foreach ($project['bootstrap'] ?? [] as $className) {
            if (!class_exists($className)) {
                $log = "Warning: Class $className setting in config/plugin/$firm/$name/bootstrap.php not found\r\n";
                echo $log;
                Log::error($log);
                continue;
            }
            /** @var Bootstrap $className */
            $className::start($worker);
        }
    }
    foreach ($projects['bootstrap'] ?? [] as $className) {
        if (!class_exists($className)) {
            $log = "Warning: Class $className setting in plugin/$firm/config/bootstrap.php not found\r\n";
            echo $log;
            Log::error($log);
            continue;
        }
        /** @var Bootstrap $className */
        $className::start($worker);
    }
}

$directory = base_path() . '/plugin';
$paths = [config_path()];
foreach (Util::scanDir($directory) as $path) {
    if (is_dir($path = "$path/config")) {
        $paths[] = $path;
    }
}
Route::load($paths);

// --- Custom initialization below ---

// Eloquent ORM bootstrap (deferred until DB is available)
$dbConfig = config('database.connections.mysql');
$auditConfig = config('database.connections.audit');

$capsule = new Capsule;
$capsule->addConnection($dbConfig, 'default');
$capsule->addConnection($auditConfig, 'audit');
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Event listeners
if (class_exists(Illuminate\Support\Facades\Event::class)) {
    Illuminate\Support\Facades\Event::listen(
        App\Payment\Event\OrderPaid::class,
        [App\Provisioning\Listener\OrderPaidListener::class, 'handle']
    );
    Illuminate\Support\Facades\Event::listen(
        App\Ticket\Event\TicketCreated::class,
        [App\Ticket\Listener\AutoAssignListener::class, 'handle']
    );
    Illuminate\Support\Facades\Event::listen(
        App\Provisioning\Event\ProvisionFailed::class,
        [App\Monitor\Service\AlertEngine::class, 'onProvisionFailed']
    );
}

// Snowflake ID generator
Common\Snowflake\SnowflakeService::init();

// Encryption
Common\Encryption\EncryptionService::init();

// Hashids
Common\Hashid\HashidService::init();

// Global helpers
if (!function_exists('hashid_encode')) {
    function hashid_encode(int $id): string {
        return Common\Hashid\HashidService::encode($id);
    }
}
if (!function_exists('hashid_decode')) {
    function hashid_decode(string $hash): ?int {
        return Common\Hashid\HashidService::decode($hash);
    }
}

// Request ID helper
if (!function_exists('request_id')) {
    function request_id(): string {
        static $id = null;
        if ($id === null) {
            $id = bin2hex(random_bytes(8));
        }
        return $id;
    }
}

// API version helper — returns the current request's API version
if (!function_exists('api_version')) {
    function api_version(): string {
        $request = \Webman\Context::get(\Webman\Http\Request::class);
        return $request?->properties['api_version'] ?? 'v1';
    }
}

// Register provisioning providers — called once at worker start
if (class_exists(\App\Provisioning\Service\ProviderFactory::class)) {
    try {
        \App\Provisioning\Service\ProviderFactory::registerDefaults();

        // DB-backed AWS registrations (if provider_apis table has aws-ec2 entry)
        $awsConfig = \App\Provisioning\Model\ProviderApi::where('code', 'aws-ec2')->where('status', 'active')->first();
        if ($awsConfig) {
            \App\Provisioning\Service\ProviderFactory::register('server', 'aws-ec2', fn() => new \App\Provisioning\Provider\AwsEc2Provider($awsConfig));
            \App\Provisioning\Service\ProviderFactory::register('disk', 'aws-ec2', fn() => new \App\Provisioning\Provider\AwsEc2Provider($awsConfig));
            \App\Provisioning\Service\ProviderFactory::register('ip', 'aws-ec2', fn() => new \App\Provisioning\Provider\AwsEc2Provider($awsConfig));
        }
    } catch (\Throwable $e) {
        // DB or provider class not available — skip registration
    }
}
