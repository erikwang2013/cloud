<?php
namespace App\Cdn\Controller;

use App\Cdn\Model\ResourceCdn;
use App\Cdn\Service\CachePurgeInterface;
use App\Provisioning\Service\ProviderFactory;
use Common\Helper\Response;

class CdnController
{
    public function index($request)
    {
        $userId = $request->userId;
        $domains = ResourceCdn::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->orderBy('created_at', 'desc')->get();

        return json(Response::success($domains));
    }

    public function show($request, int $id)
    {
        $userId = $request->userId;
        $cdn = ResourceCdn::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->findOrFail($id);

        return json(Response::success($cdn));
    }

    public function purgeCache($request, int $id)
    {
        $userId = $request->userId;
        $cdn = ResourceCdn::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->findOrFail($id);

        $urls = $request->input('urls', []);
        if (empty($urls)) {
            return json(Response::error('urls array is required'));
        }

        try {
            $provider = (new ProviderFactory())->createFromResource($cdn->resource);
            if ($provider instanceof CachePurgeInterface) {
                $result = $provider->purgeCache($cdn->resource, $urls);
                return json(Response::success($result, 'Cache purge requested'));
            }
            return json(Response::error('Provider does not support cache purge'));
        } catch (\Throwable $e) {
            return json(Response::error('Purge failed: ' . $e->getMessage()));
        }
    }

    public function stats($request, int $id)
    {
        $userId = $request->userId;
        $cdn = ResourceCdn::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->findOrFail($id);

        return json(Response::success([
            'cdn_domain'  => $cdn->cdn_domain,
            'plan'        => $cdn->plan,
            'status'      => $cdn->status,
            'purged_at'   => $cdn->purged_at,
        ]));
    }
}
