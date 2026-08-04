<?php
namespace App\Billing\Service;

use App\Provisioning\Model\Resource;
use App\Provisioning\Model\ResourceServer;
use App\Provisioning\Model\ResourceDisk;
use App\Provisioning\Model\ResourceIp;
use Illuminate\Database\Capsule\Manager as Capsule;

class MeterCollector
{
    public function collectAll(): void
    {
        $resources = Resource::where('status', 'active')->get();
        $now = date('Y-m-d H:i:s');

        foreach ($resources as $resource) {
            $this->collectResourceMetrics($resource, $now);
        }
    }

    private function collectResourceMetrics(Resource $resource, string $sampleAt): void
    {
        $metrics = [];

        switch ($resource->type) {
            case 'server':
                $metrics = $this->collectServerMetrics($resource);
                break;
            case 'disk':
                $metrics = $this->collectDiskMetrics($resource);
                break;
            case 'storage':
                $metrics = $this->collectStorageMetrics($resource);
                break;
            case 'cdn':
                $metrics = $this->collectCdnMetrics($resource);
                break;
        }

        foreach ($metrics as $metric => $value) {
            Capsule::table('resource_metrics')->insert([
                'resource_id' => $resource->id,
                'metric'      => $metric,
                'value'       => $value,
                'sample_at'   => $sampleAt,
            ]);
        }
    }

    private function collectServerMetrics(Resource $resource): array
    {
        $server = ResourceServer::where('resource_id', $resource->id)->first();
        if (!$server) return [];

        try {
            $provider = (new \App\Provisioning\Service\ProviderFactory())->createFromResource($resource);
            $status   = $provider->status($resource);
            return [
                'cpu_percent'    => $status->metrics['cpu_percent'] ?? 0,
                'mem_percent'    => $status->metrics['mem_percent'] ?? 0,
                'bw_in_gb'       => round(($status->metrics['bw_in'] ?? 0) / 1073741824, 4),
                'bw_out_gb'      => round(($status->metrics['bw_out'] ?? 0) / 1073741824, 4),
                'disk_usage_gb'  => $status->metrics['disk_usage'] ?? 0,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    private function collectDiskMetrics(Resource $resource): array
    {
        $disk = ResourceDisk::where('resource_id', $resource->first())->first();
        if (!$disk) return [];
        return ['disk_usage_gb' => $disk->size_gb ?? 0, 'disk_io_read' => 0, 'disk_io_write' => 0];
    }

    private function collectStorageMetrics(Resource $resource): array
    {
        $bucket = \App\Storage\Model\StorageBucket::where('resource_id', $resource->id)->first();
        if (!$bucket) return [];
        return ['storage_used_gb' => $bucket->used_gb, 'storage_requests' => 0];
    }

    private function collectCdnMetrics(Resource $resource): array
    {
        return ['cdn_bandwidth_gb' => 0, 'cdn_requests' => 0];
    }
}
