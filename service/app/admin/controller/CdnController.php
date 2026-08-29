<?php
namespace App\admin\controller;

use App\cdn\model\ResourceCdn;
use Common\helper\Response;
use Common\security\AuditLogger;

class CdnController
{
    private const PLANS = ['standard', 'pro', 'enterprise'];

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
        $plan = (string) $request->input('plan', '');
        if (!in_array($plan, self::PLANS, true)) {
            return json(Response::error(400, 'Invalid CDN plan'));
        }
        $cdn = ResourceCdn::findOrFail($id);
        $cdn->update(['plan' => $plan]);

        AuditLogger::record('admin_cdn_update_plan', [
            'user_id' => $request->userId,
            'input'   => ['cdn_id' => $id, 'plan' => $plan],
        ], $request);

        return json(Response::success($cdn, 'CDN plan updated'));
    }
}
