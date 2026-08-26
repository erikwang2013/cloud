<?php
namespace App\websocket;

use Common\auth\JwtAuth;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;
use support\Redis;

/**
 * WebSocket server for real-time client push.
 *
 * JWT 认证在连接后首条消息完成（{type:"auth", token}），避免 token 进
 * nginx/代理访问日志。
 * Cross-process push via Redis Pub/Sub: HTTP 进程 publish 到 "ws:{userId}" /
 * "ws:broadcast"，各 WS worker 内 fork 的订阅子进程接收后经 socketpair
 * 转发给本进程对应连接，实现多进程（count=2）与 HTTP 进程间的互通。
 */
class WebSocketServer
{
    private const BROADCAST_CHANNEL = 'ws:broadcast';

    /** @var array<int, array<int, TcpConnection>> userId => [connectionId => connection] */
    private static array $connections = [];

    /** @var resource|null 父进程读端（订阅子进程写入） */
    private $subSocket = null;

    /** 从子进程累积的未解析帧缓冲 */
    private string $subBuffer = '';

    public function onWorkerStart(Worker $worker): void
    {
        echo "WebSocket server started on ws://0.0.0.0:8282\n";
        $this->startSubscriber();
    }

    public function onConnect(TcpConnection $connection): void
    {
        // 认证移到首条消息（避免 token 进访问日志）；30s 内未认证则关闭
        $connection->authTimer = Timer::add(30, function () use ($connection) {
            if (empty($connection->userId)) {
                $connection->close();
            }
        }, [], false);
    }

    public function onMessage(TcpConnection $connection, $data): void
    {
        $msg = json_decode($data, true);
        if (!is_array($msg)) return;

        if (empty($connection->userId)) {
            $this->authenticateConnection($connection, $msg);
            return;
        }

        // Heartbeat
        if (($msg['type'] ?? '') === 'ping') {
            $connection->send(json_encode(['type' => 'pong', 'ts' => time()]));
        }
    }

    private function authenticateConnection(TcpConnection $connection, array $msg): void
    {
        if (($msg['type'] ?? '') !== 'auth') {
            $connection->send(json_encode(['type' => 'error', 'message' => 'Authentication required']));
            $connection->close();
            return;
        }

        $userId = $this->authenticate((string) ($msg['token'] ?? ''));
        if (!$userId) {
            $connection->send(json_encode(['type' => 'error', 'message' => 'Authentication failed']));
            $connection->close();
            return;
        }

        if (!empty($connection->authTimer)) {
            Timer::del($connection->authTimer);
        }

        $connection->userId = $userId;
        self::$connections[$userId][$connection->id] = $connection;
        $connection->send(json_encode(['type' => 'connected', 'user_id' => $userId]));
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
     * Push event to a specific user's all connections (cross-process via Redis).
     */
    public static function send(int $userId, string $event, array $data): void
    {
        if (!\Common\feature\FeatureFlags::isEnabled('websocket_push')) {
            return;
        }
        $payload = json_encode([
            'type'      => 'event',
            'event'     => $event,
            'data'      => $data,
            'timestamp' => date('c'),
        ]);

        try {
            Redis::publish("ws:{$userId}", $payload);
        } catch (\Throwable $e) {
            // Redis 不可用时静默降级
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

        try {
            Redis::publish(self::BROADCAST_CHANNEL, $payload);
        } catch (\Throwable $e) {
            // Redis 不可用时静默降级
        }
    }

    /**
     * 启动 Redis 订阅：fork 子进程阻塞 psubscribe，经 socketpair 把消息
     * 送回父进程（事件循环内由 Timer 轮询读取并转发给本地连接）。
     */
    private function startSubscriber(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            echo "WebSocket push disabled: pcntl/stream_socket_pair unavailable\n";
            return;
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            echo "WebSocket push disabled: stream_socket_pair failed\n";
            return;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($pair[0]);
            fclose($pair[1]);
            echo "WebSocket push disabled: pcntl_fork failed\n";
            return;
        }

        if ($pid === 0) {
            // 子进程：阻塞订阅，消息以 [4B channel 长度][channel][message] 帧写入父端
            fclose($pair[0]);
            $parentPid = posix_getppid();
            stream_set_blocking($pair[1], false);

            try {
                Redis::psubscribe('ws:*', function ($message, $channel) use ($pair, $parentPid) {
                    if (!posix_kill($parentPid, 0)) {
                        exit(0); // 父进程已退出
                    }
                    $frame = pack('N2', strlen($channel), strlen($message)) . $channel . $message;
                    if (@fwrite($pair[1], $frame) === false) {
                        exit(0); // 父端关闭，EPIPE
                    }
                });
            } catch (\Throwable $e) {
                // 订阅失败（如 Redis 未就绪），退出子进程
                exit(0);
            }
            exit(0);
        }

        // 父进程（WS worker）：事件循环内轮询读端并转发
        fclose($pair[1]);
        $this->subSocket = $pair[0];
        stream_set_blocking($this->subSocket, false);
        Timer::add(0.05, function () {
            $this->drainSubscriber();
        });
    }

    /**
     * 读取子进程帧并转发到本进程对应连接。
     */
    private function drainSubscriber(): void
    {
        if (!$this->subSocket) return;

        while (($chunk = @fread($this->subSocket, 8192)) !== false && $chunk !== '') {
            $this->subBuffer .= $chunk;
        }

        while (strlen($this->subBuffer) >= 8) {
            $header = unpack('N2', substr($this->subBuffer, 0, 8));
            $total  = 8 + $header[1] + $header[2];
            if (strlen($this->subBuffer) < $total) break;

            $channel = substr($this->subBuffer, 8, $header[1]);
            $message = substr($this->subBuffer, 8 + $header[1], $header[2]);
            $this->subBuffer = substr($this->subBuffer, $total);

            $this->forward($channel, $message);
        }
    }

    /**
     * 把订阅消息推给本进程对应连接。
     */
    private function forward(string $channel, string $message): void
    {
        if ($channel === self::BROADCAST_CHANNEL) {
            foreach (self::$connections as $conns) {
                foreach ($conns as $conn) {
                    $this->safeSend($conn, $message);
                }
            }
            return;
        }

        $userId = (int) substr($channel, 3); // 去掉 "ws:" 前缀
        if ($userId <= 0 || empty(self::$connections[$userId])) return;

        foreach (self::$connections[$userId] as $conn) {
            $this->safeSend($conn, $message);
        }
    }

    private function safeSend(TcpConnection $conn, string $payload): void
    {
        try {
            $conn->send($payload);
        } catch (\Throwable $e) {
            // Connection might be stale
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
