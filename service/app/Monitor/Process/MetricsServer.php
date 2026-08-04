<?php
namespace App\Monitor\Process;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Common\Metrics\Collector;
use Common\Metrics\Render;
use Redis;

class MetricsServer
{
    public function onWorkerStart(Worker $worker): void
    {
        echo "Metrics server started on http://0.0.0.0:9100\n";

        try {
            $redis = new Redis();
            $redis->connect(
                getenv('REDIS_HOST') ?: '127.0.0.1',
                (int)(getenv('REDIS_PORT') ?: 6379)
            );
            Collector::setRedis($redis);
        } catch (\Throwable) {}
    }

    public function onMessage(TcpConnection $connection, string $data): void
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
