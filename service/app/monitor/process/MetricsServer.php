<?php
namespace App\monitor\process;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Common\metrics\Collector;
use Common\metrics\Render;
use Redis;

class MetricsServer
{
    public function onWorkerStart(Worker $worker): void
    {
        echo "Metrics server started on {$worker->getSocketName()}\n";

        try {
            $redis = new Redis();
            $redis->connect(
                getenv('REDIS_HOST') ?: '127.0.0.1',
                (int)(getenv('REDIS_PORT') ?: 6379),
                2
            );
            if ($password = getenv('REDIS_PASSWORD')) {
                $redis->auth($password);
            }
            Collector::setRedis($redis);
        } catch (\Throwable $e) {
            echo "Metrics Redis connection failed: {$e->getMessage()}\n";
        }
    }

    public function onMessage(TcpConnection $connection, $data): void
    {
        $response = Render::text();
        $headers = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/plain; version=0.0.4\r\n"
            . "Content-Length: " . strlen($response) . "\r\n"
            . "Connection: close\r\n\r\n";

        $connection->send($headers . $response);
        $connection->close();
    }
}
