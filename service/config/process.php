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
];
