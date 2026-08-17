<?php
/**
 * Custom process configuration.
 * Each entry spawns a Workerman worker alongside the HTTP server.
 */
return [
    // WebSocket server for real-time client push
    'websocket' => [
        'handler'     => App\WebSocket\WebSocketServer::class,
        'listen'      => 'websocket://0.0.0.0:' . (getenv('WS_PORT') ?: '8282'),
        'count'       => 2, // WebSocket worker count
        'constructor' => [], // Passed to handler constructor
    ],

    // Prometheus metrics endpoint (independent process, no middleware)
    'metrics' => [
        'handler'     => App\Monitor\Process\MetricsServer::class,
        'listen'      => 'http://127.0.0.1:' . (getenv('METRICS_PORT') ?: '9100'),
        'count'       => 1,
        'constructor' => [],
    ],

    // Redis queue consumer — scans app/ for Webman\RedisQueue\Consumer implementations
    // (provisioning, notification_email/sms/push, etc.)
    'queue_consumer' => [
        'handler'     => Webman\RedisQueue\Process\Consumer::class,
        'count'       => 2,
        'constructor' => [
            'consumer_dir' => app_path(),
        ],
    ],

    // Cron scheduler — evaluates config/cron.php 5-field expressions every minute
    'cron' => [
        'handler'     => App\Cron\CronRunner::class,
        'count'       => 1,
        'constructor' => [],
    ],

    // etcd registry: lease keepalive + peer liveness polling (gRPC discovery)
    'grpc_registry' => [
        'handler'     => App\Grpc\RegistryProcess::class,
        'count'       => 1,
        'constructor' => [],
    ],
];
