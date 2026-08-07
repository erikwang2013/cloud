<?php
namespace App\WebSocket;

use Common\Auth\JwtAuth;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

/**
 * WebSocket server for real-time client push.
 *
 * Clients connect with JWT token: ws://host:8282?token=xxx
 * Server pushes events via Redis Pub/Sub across worker processes.
 */
class WebSocketServer
{
    /** @var array<int, array<int, TcpConnection>> userId => [connectionId => connection] */
    private static array $connections = [];

    public function onWorkerStart(Worker $worker): void
    {
        echo "WebSocket server started on ws://0.0.0.0:8282\n";
    }

    public function onConnect(TcpConnection $connection): void
    {
        // Parse JWT token from query string
        $query = [];
        parse_str($connection->queryString() ?? '', $query);

        $token  = $query['token'] ?? '';
        $userId = $this->authenticate($token);

        if (!$userId) {
            $connection->send(json_encode(['type' => 'error', 'message' => 'Authentication failed']));
            $connection->close();
            return;
        }

        // Store connection
        self::$connections[$userId][$connection->id] = $connection;
        $connection->userId = $userId;

        $connection->send(json_encode(['type' => 'connected', 'user_id' => $userId]));
    }

    public function onMessage(TcpConnection $connection, $data): void
    {
        $msg = json_decode($data, true);
        if (!$msg) return;

        // Heartbeat
        if (($msg['type'] ?? '') === 'ping') {
            $connection->send(json_encode(['type' => 'pong', 'ts' => time()]));
        }
    }

    public function onClose(TcpConnection $connection): void
    {
        $userId = $connection->userId ?? null;
        if ($userId && isset(self::$connections[$userId])) {
            unset(self::$connections[$userId][$connection->id]);
            if (empty(self::$connections[$userId])) {
                unset(self::$connections[$userId]);
            }
        }
    }

    /**
     * Push event to a specific user's all connections.
     */
    public static function send(int $userId, string $event, array $data): void
    {
        if (empty(self::$connections[$userId])) return;

        $payload = json_encode([
            'type'      => 'event',
            'event'     => $event,
            'data'      => $data,
            'timestamp' => date('c'),
        ]);

        foreach (self::$connections[$userId] as $conn) {
            try {
                $conn->send($payload);
            } catch (\Throwable $e) {
                // Connection might be stale
            }
        }
    }

    /**
     * Broadcast to all connected users (admin alerts, maintenance notices).
     */
    public static function broadcast(string $event, array $data): void
    {
        $payload = json_encode([
            'type'      => 'broadcast',
            'event'     => $event,
            'data'      => $data,
            'timestamp' => date('c'),
        ]);

        foreach (self::$connections as $userId => $conns) {
            foreach ($conns as $conn) {
                try {
                    $conn->send($payload);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    private function authenticate(string $token): ?int
    {
        if (empty($token)) return null;
        try {
            $jwt   = new JwtAuth();
            $payload = $jwt->verify($token);
            return $payload['sub'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
