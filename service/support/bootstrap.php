<?php
// Bootstrap — loaded by webman on worker start
// Based on vendor/workerman/webman-framework/src/support/bootstrap.php

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
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

// 为 Eloquent 装配事件分发器（含模型事件），并让 Illuminate Facade 拿到容器，
// 否则 Event Facade 抛 "A facade root has not been set" / "Target class [events] does not exist"
$dispatcher = new Dispatcher($capsule->getContainer());
$capsule->setEventDispatcher($dispatcher);
$capsule->setAsGlobal();
$capsule->bootEloquent();
Illuminate\Support\Facades\Facade::setFacadeApplication($capsule->getContainer());

// Redis 容器绑定：illuminate/redis 由 config/redis.php 驱动。
// 缺失绑定时容器自动装配出裸 phpredis 实例（未连接），Redis::get() 抛 "server went away"。
$capsule->getContainer()->singleton('redis', fn() => support\Redis::manager());

// Event listeners — 直接使用 dispatcher 实例（不依赖容器中的 events 服务）
$dispatcher->listen(
    App\payment\event\OrderPaid::class,
    [App\provisioning\listener\OrderPaidListener::class, 'handle']
);
$dispatcher->listen(
    App\ticket\event\TicketCreated::class,
    [App\ticket\listener\AutoAssignListener::class, 'handle']
);
$dispatcher->listen(
    App\provisioning\event\ProvisionFailed::class,
    [App\monitor\service\AlertEngine::class, 'onProvisionFailed']
);

// Snowflake ID generator
Common\snowflake\SnowflakeService::init();

// Encryption
Common\encryption\EncryptionService::init();

// encryptable 字段加密：运行时无 Illuminate 容器绑定，Encryption::php() 走 EnvEncryptableConfig
// fallback（直接读 env 原始 base64 串，密钥长度校验失败抛 MissingEncryptionKeyException）。
// 显式指向插件配置（key 已在配置层 base64 解码），否则注册/登录/改资料等加密字段写入全部 500。
\Erikwang2013\Encryptable\Encryption::setFallbackConfig(
    new \Erikwang2013\Encryptable\Bridge\Webman\WebmanPluginEncryptableConfig()
);

// 确定性查询守卫：登录/刷新/注册唯一性等按密文等值匹配加密列，
// 仅 ECB 无随机 IV（同明文同密文）；CBC/GCM 每次随机 IV，查询永不命中且无任何报错。
// 换 cipher 前必须先对存量数据做重加密迁移（见 docs/test-reports/2026-08-26-fix-service.md）。
$activeCipher = (new \Erikwang2013\Encryptable\PHPEncrypter(
    new \Erikwang2013\Encryptable\Bridge\Webman\WebmanPluginEncryptableConfig()
))->cipher();
if (!in_array($activeCipher, ['aes-128-ecb', 'aes-256-ecb'], true)) {
    throw new \RuntimeException(
        "Encryptable cipher [{$activeCipher}] 非确定性加密：按密文等值查询的路径（登录/刷新/唯一性校验）将全部失效，仅支持 ECB。需先完成存量数据重加密迁移才能更换 cipher。"
    );
}

// Hashids
Common\hashid\HashidService::init();

// Global helpers
if (!function_exists('hashid_encode')) {
    function hashid_encode(int $id): string {
        return Common\hashid\HashidService::encode($id);
    }
}
if (!function_exists('hashid_decode')) {
    function hashid_decode(string $hash): ?int {
        return Common\hashid\HashidService::decode($hash);
    }
}

// storage_path helper — 属于 webman 骨架层（webman/webman），本项目只装 framework 故缺失
if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string {
        return base_path() . '/storage' . ($path ? '/' . ltrim($path, '/') : '');
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
if (class_exists(\App\provisioning\service\ProviderFactory::class)) {
    try {
        \App\provisioning\service\ProviderFactory::registerDefaults();

        // DB-backed AWS registrations (if provider_apis table has aws-ec2 entry)
        $awsConfig = \App\provisioning\model\ProviderApi::where('code', 'aws-ec2')->where('status', 'active')->first();
        if ($awsConfig) {
            \App\provisioning\service\ProviderFactory::register('server', 'aws-ec2', fn() => new \App\provisioning\provider\AwsEc2Provider($awsConfig));
            \App\provisioning\service\ProviderFactory::register('disk', 'aws-ec2', fn() => new \App\provisioning\provider\AwsEc2Provider($awsConfig));
            \App\provisioning\service\ProviderFactory::register('ip', 'aws-ec2', fn() => new \App\provisioning\provider\AwsEc2Provider($awsConfig));
        }
    } catch (\Throwable $e) {
        // DB or provider class not available — skip registration
    }
}
