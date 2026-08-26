<?php
namespace App\monitor\service;

use App\provisioning\model\Resource;
use App\provisioning\model\ProvisionTask;
use App\provisioning\service\ProviderFactory;
use App\notification\service\NotificationDispatcher;
use Illuminate\Support\Facades\Redis;

class ResourceMonitor
{
    private ProviderFactory $factory;

    public function __construct()
    {
        $this->factory = new ProviderFactory();
    }

    public function collectAllMetrics(): void
    {
        $resources = Resource::where('status', 'active')
            ->where('type', 'server')
            ->get();

        foreach ($resources as $resource) {
            $task = ProvisionTask::where('resource_id', $resource->id)->first();
            if (!$task) continue;

            try {
                $provider = $this->factory->create($task);
                $status   = $provider->status($resource);

                Redis::hset("resource:{$resource->id}:status", 'status', $status->status);
                Redis::hset("resource:{$resource->id}:metrics", 'cpu', $status->metrics['cpu_percent'] ?? 0);
                Redis::hset("resource:{$resource->id}:metrics", 'mem', $status->metrics['mem_percent'] ?? 0);
                Redis::expire("resource:{$resource->id}:metrics", 3600);

                if ($status->status === 'stopped' || $status->status === 'error') {
                    $this->checkDowntime($resource);
                }
            } catch (\Exception $e) {
                // Skip failed metric collection
            }
        }
    }

    private function checkDowntime(Resource $resource): void
    {
        $key = "downtime:{$resource->id}";
        $count = Redis::incr($key);
        Redis::expire($key, 600);

        if ($count >= 3) {
            $alertEngine = new AlertEngine();
            $alertEngine->trigger('server_down', $resource, [
                'consecutive_checks' => $count,
            ]);
        }
    }

    public function checkExpirations(): void
    {
        $windows = [7, 3, 1];

        foreach ($windows as $days) {
            $expiring = Resource::where('status', 'active')
                ->whereBetween('expired_at', [
                    date('Y-m-d H:i:s', strtotime("+{$days} days")),
                    date('Y-m-d H:i:s', strtotime("+{$days} days + 1 hour")),
                ])
                ->get();

            foreach ($expiring as $resource) {
                $dispatcher = new NotificationDispatcher();
                $dispatcher->dispatch($resource->user_id, 'resource_expiring', [
                    'resource_id'   => $resource->id,
                    'resource_type' => $resource->type,
                    'days_left'     => (string)$days,
                    'expired_at'    => $resource->expired_at,
                ]);
            }
        }
    }

    public function checkSslCertificates(): void
    {
        $domains = Resource::where('type', 'domain')->where('status', 'active')->get();

        foreach ($domains as $domain) {
            $cert = $this->getCertInfo($domain->specs['domain_name'] ?? '');
            if (!$cert || !isset($cert['validTo_time_t'])) continue;

            $daysLeft = ($cert['validTo_time_t'] - time()) / 86400;

            if ($daysLeft <= 30) {
                $alertEngine = new AlertEngine();
                $alertEngine->trigger('ssl_expiring', $domain, [
                    'domain'    => $domain->specs['domain_name'] ?? '',
                    'days_left' => round($daysLeft),
                ]);
            }
        }
    }

    private function getCertInfo(string $domain): ?array
    {
        if (empty($domain)) return null;
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false]]);
        $stream  = @stream_socket_client("ssl://{$domain}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$stream) return null;

        $cert = stream_context_get_params($stream);
        fclose($stream);

        return openssl_x509_parse($cert['options']['ssl']['peer_certificate']);
    }
}
