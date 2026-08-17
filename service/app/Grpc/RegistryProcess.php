<?php

namespace App\Grpc;

use Workerman\Timer;
use Workerman\Worker;

/**
 * Dedicated worker: registers this service in etcd, renews the lease every 5s
 * (TTL 15s), and polls the kvm-server directory every 5s to track liveness.
 * etcd gRPC-gateway has no watch stream, so polling is the PHP-side equivalent.
 */
class RegistryProcess
{
    public function onWorkerStart(Worker $worker): void
    {
        $baseUrl = getenv('ETCD_URL') ?: 'http://127.0.0.1:2379';
        $registry = new EtcdRegistry($baseUrl);
        $leaseId = 0;
        try {
            $leaseId = $registry->register('service', '1.0', [], ['app' => 'service']);
        } catch (\Throwable $e) {
            echo "etcd register failed: {$e->getMessage()}\n";
        }

        Timer::add(5, function () use ($registry, &$leaseId) {
            if ($leaseId > 0) {
                try {
                    $registry->keepalive($leaseId);
                } catch (\Throwable $e) {
                    echo "etcd keepalive failed: {$e->getMessage()}\n";
                }
            }
        });

        Timer::add(5, function () use ($registry) {
            try {
                RegistryStatus::set('kvm-server', $registry->discover('kvm-server'));
            } catch (\Throwable $e) {
                echo "etcd discover failed: {$e->getMessage()}\n";
            }
        });
    }
}
