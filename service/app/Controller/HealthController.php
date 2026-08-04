<?php
namespace App\Controller;

use Common\Helper\Response;
use Illuminate\Database\Capsule\Manager as Capsule;
use Redis;

class HealthController
{
    public function index()
    {
        return json(Response::success([
            'status'    => 'healthy',
            'timestamp' => date('c'),
            'version'   => getenv('APP_VERSION') ?: trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?: 'dev'),
        ]));
    }

    public function live()
    {
        return json(Response::success([
            'status'    => 'alive',
            'timestamp' => date('c'),
            'uptime'    => $this->getUptime(),
        ]));
    }

    public function ready()
    {
        $redis  = $this->getRedis();
        $cacheKey = 'health:ready';
        $ttl      = 5;

        $cached = $redis ? $redis->get($cacheKey) : null;
        if ($cached) {
            return json(json_decode($cached, true));
        }

        $checks = [
            'database'      => $this->checkDatabase(),
            'redis'         => $this->checkRedis($redis),
            'elasticsearch' => $this->checkElasticsearch(),
            'disk_space'    => $this->checkDiskSpace(),
            'queue_depth'   => $this->checkQueueDepth($redis),
        ];

        $healthy = !in_array(false, array_column($checks, 'healthy'), true);
        $statusCode = $healthy ? 200 : 503;

        $result = [
            'status'    => $healthy ? 'ok' : 'degraded',
            'timestamp' => date('c'),
            'checks'    => $checks,
            'version'   => getenv('APP_VERSION') ?: trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?: 'dev'),
            'uptime'    => $this->getUptime(),
        ];

        if ($redis) {
            $redis->setex($cacheKey, $ttl, json_encode($result));
        }

        http_response_code($statusCode);
        return json($result);
    }

    public function deps()
    {
        $redis = $this->getRedis();
        $cacheKey = 'health:deps';
        $ttl      = 5;

        $cached = $redis ? $redis->get($cacheKey) : null;
        if ($cached) {
            return json(json_decode($cached, true));
        }

        $result = [
            'database'      => $this->depStatus('MySQL', $this->checkDatabase()),
            'redis'         => $this->depStatus('Redis', $this->checkRedis($redis)),
            'elasticsearch' => $this->depStatus('Elasticsearch', $this->checkElasticsearch()),
            'version'       => getenv('APP_VERSION') ?: trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?: 'dev'),
            'uptime'        => $this->getUptime(),
            'timestamp'     => date('c'),
        ];

        if ($redis) {
            $redis->setex($cacheKey, $ttl, json_encode($result));
        }

        return json(Response::success($result));
    }

    private function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            Capsule::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);
            return ['healthy' => true, 'latency_ms' => $latency];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkRedis(?Redis $redis): array
    {
        if (!$redis) {
            return ['healthy' => false, 'error' => 'Redis not configured'];
        }
        $start = microtime(true);
        try {
            $redis->ping();
            $latency = round((microtime(true) - $start) * 1000, 2);
            return ['healthy' => true, 'latency_ms' => $latency];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkElasticsearch(): array
    {
        $hosts = config('plugin.erikwang2013.webman-scout.app.elasticsearch.hosts');
        if (empty($hosts)) {
            return ['healthy' => true, 'note' => 'not configured'];
        }
        $host = is_array($hosts) ? $hosts[0] : $hosts;
        $start = microtime(true);
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 5]);
            $resp = $client->get("{$host}/_cluster/health");
            $body = json_decode((string) $resp->getBody(), true);
            $latency = round((microtime(true) - $start) * 1000, 2);
            return [
                'healthy'   => in_array($body['status'] ?? '', ['green', 'yellow']),
                'status'    => $body['status'] ?? 'unknown',
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkDiskSpace(): array
    {
        $path = runtime_path() ?: sys_get_temp_dir();
        $free = disk_free_space($path);
        $total = disk_total_space($path);
        $percent = $total > 0 ? round(($free / $total) * 100, 1) : 0;
        return [
            'healthy'      => $percent > 5,
            'free_percent' => $percent,
            'free_gb'      => round($free / 1024 / 1024 / 1024, 1),
        ];
    }

    private function checkQueueDepth(?Redis $redis): array
    {
        if (!$redis) {
            return ['healthy' => true, 'note' => 'not configured'];
        }
        try {
            $depth = $redis->lLen('redis-queue:provisioning') ?: 0;
            return [
                'healthy' => $depth < 500,
                'depth'   => $depth,
            ];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }
    }

    private function depStatus(string $name, array $check): array
    {
        return [
            'name'       => $name,
            'healthy'    => $check['healthy'] ?? false,
            'latency_ms' => $check['latency_ms'] ?? null,
            'error'      => $check['error'] ?? null,
        ];
    }

    private function getUptime(): string
    {
        static $startedAt;
        if (!$startedAt) {
            $startedAt = date('c');
        }
        return $startedAt;
    }

    private function getRedis(): ?Redis
    {
        try {
            $redis = new Redis();
            $redis->connect(
                getenv('REDIS_HOST') ?: '127.0.0.1',
                (int)(getenv('REDIS_PORT') ?: 6379),
                2
            );
            return $redis;
        } catch (\Throwable) {
            return null;
        }
    }
}
