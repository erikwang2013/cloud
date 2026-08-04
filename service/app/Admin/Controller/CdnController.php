<?php
namespace App\Admin\Controller;

use App\Cdn\Model\ResourceCdn;
use Common\Helper\Response;

class CdnController
{
    public function index()
    {
        $domains = ResourceCdn::with('resource')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($d) => [
                'id'           => $d->id,
                'resource_id'  => $d->resource_id,
                'cdn_domain'   => $d->cdn_domain,
                'origin_type'  => $d->origin_type,
                'plan'         => $d->plan,
                'status'       => $d->status,
                'user_id'      => $d->resource->user_id ?? null,
            ]);
        return json(Response::success($domains));
    }

    public function updatePlan($request, int $id)
    {
        $cdn = ResourceCdn::findOrFail($id);
        $cdn->update(['plan' => $request->input('plan')]);
        return json(Response::success($cdn, 'CDN plan updated'));
    }
}
