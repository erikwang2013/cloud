<?php
namespace App\cdn\provider;

use App\provisioning\service\ProviderInterface;
use App\provisioning\model\ProvisionTask;
use App\provisioning\model\ProvisionResult;
use App\provisioning\model\Resource;
use App\provisioning\model\resourcestatus;
use App\cdn\model\ResourceCdn;
use App\cdn\service\CachePurgeInterface;

class CdnProvider implements ProviderInterface, CachePurgeInterface
{
    public function create(ProvisionTask $task): ProvisionResult
    {
        $params = json_decode($task->params, true);
        $domain    = $params['cdn_domain'] ?? '';
        $originType = $params['origin_type'] ?? 'server';
        $originValue = $params['origin_value'] ?? '';

        try {
            $cdn = ResourceCdn::where('resource_id', $task->resource_id)->first();
            if ($cdn) {
                $cdn->update([
                    'cdn_domain'  => $domain,
                    'origin_type' => $originType,
                    'origin_value' => $originValue,
                    'status'      => 'active',
                ]);
            }

            return ProvisionResult::success([
                'cdn_domain'  => $domain,
                'origin_type' => $originType,
            ]);
        } catch (\Throwable $e) {
            return ProvisionResult::retryable('CDN setup failed: ' . $e->getMessage());
        }
    }

    public function destroy(Resource $resource): ProvisionResult
    {
        $cdn = ResourceCdn::where('resource_id', $resource->id)->first();
        if ($cdn) {
            $cdn->update(['status' => 'deleted']);
        }
        return ProvisionResult::success([]);
    }

    public function status(Resource $resource): ResourceStatus
    {
        $cdn = ResourceCdn::where('resource_id', $resource->id)->first();
        $rs = new ResourceStatus();
        $rs->status  = $cdn ? $cdn->status : 'unknown';
        $rs->metrics = [];
        return $rs;
    }

    public function renew(Resource $resource, int $months): ProvisionResult
    {
        return ProvisionResult::success([]);
    }

    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult
    {
        $cdn = ResourceCdn::where('resource_id', $resource->id)->first();
        if ($cdn && isset($newSpecs['plan'])) {
            $cdn->update(['plan' => $newSpecs['plan']]);
        }
        return ProvisionResult::success(['message' => 'CDN plan upgraded']);
    }

    public function consoleUrl(Resource $resource): string
    {
        return '';
    }

    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult
    {
        return ProvisionResult::success([]);
    }

    public function createDisk(ProvisionTask $task): ProvisionResult
    {
        return ProvisionResult::success([]);
    }

    public function createIp(ProvisionTask $task): ProvisionResult
    {
        return ProvisionResult::success([]);
    }

    public function purgeCache(Resource $resource, array $urls): array
    {
        $cdn = ResourceCdn::where('resource_id', $resource->id)->first();
        if (!$cdn) {
            return ['purged' => 0, 'error' => 'CDN resource not found'];
        }

        $cdn->update(['purged_at' => date('Y-m-d H:i:s')]);
        return ['purged' => count($urls), 'urls' => $urls];
    }
}
