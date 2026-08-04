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
];
