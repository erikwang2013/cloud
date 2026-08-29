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
        $params = json_decode($task->params, true) ?: [];
        $domain       = $params['cdn_domain'] ?? '';
        $originType   = $params['origin_type'] ?? 'server';
        $originValue  = $params['origin_value'] ?? '';
        $providerType = $params['provider_type'] ?? 'cloudflare';
        $accountId    = isset($params['provider_account_id']) ? (int) $params['provider_account_id'] : null;

        try {
            $cdn = ResourceCdn::firstOrNew(['resource_id' => $task->resource_id]);
            $cdn->fill([
                'cdn_domain'          => $domain,
                'origin_type'         => $originType,
                'origin_value'        => $originValue,
                'provider_type'       => $providerType,
                'provider_account_id' => $accountId,
            ]);

            [$adapter, $accountId] = CdnAdapterFactory::resolve($providerType, $accountId ?: $cdn->provider_account_id);
            $cdn->provider_account_id = $accountId;
            $result = $adapter->createDomain($cdn);

            $cdn->provider_domain_id = $result['provider_domain_id'] ?? $cdn->provider_domain_id;
            $cdn->zone_id            = $result['zone_id'] ?? $cdn->zone_id;
            $cdn->status             = 'active';
            $cdn->save();

            return ProvisionResult::success([
                'cdn_domain'         => $domain,
                'origin_type'        => $originType,
                'provider_domain_id' => $cdn->provider_domain_id,
            ]);
        } catch (\Throwable $e) {
            return ProvisionResult::retryable('CDN setup failed: ' . $e->getMessage());
        }
    }

    public function destroy(Resource $resource): ProvisionResult
    {
        $cdn = ResourceCdn::where('resource_id', $resource->id)->first();
        if (!$cdn) {
            return ProvisionResult::success([]);
        }
        try {
            if ($cdn->status !== 'deleted') {
                [$adapter] = CdnAdapterFactory::resolve($cdn->provider_type, $cdn->provider_account_id, true);
                $adapter->disableDomain($cdn);
                $cdn->update(['status' => 'deleted']);
            }
        } catch (\Throwable $e) {
            return ProvisionResult::retryable('CDN disable failed: ' . $e->getMessage());
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
        try {
            [$adapter] = CdnAdapterFactory::resolve($cdn->provider_type, $cdn->provider_account_id, true);
            $result = $adapter->purgeCache($cdn, $urls);
            $cdn->update(['purged_at' => date('Y-m-d H:i:s')]);
            return ['purged' => count($urls), 'urls' => $urls] + $result;
        } catch (\Throwable $e) {
            return ['purged' => 0, 'error' => $e->getMessage()];
        }
    }
}
