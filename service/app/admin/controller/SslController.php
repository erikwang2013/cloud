<?php
namespace App\admin\controller;

use App\ssl\model\SslPlan;
use App\ssl\model\SslCertificate;
use Common\helper\Response;

class SslController
{
    public function plans()
    {
        $plans = SslPlan::orderBy('cert_type')->orderBy('validity_days')->get();
        return json(Response::success($plans));
    }

    public function storePlan($request)
    {
        $plan = SslPlan::create($request->all());
        return json(Response::success($plan, 'SSL plan created'));
    }

    public function updatePlan($request, int $id)
    {
        $plan = SslPlan::findOrFail($id);
        $plan->update($request->all());
        return json(Response::success($plan, 'SSL plan updated'));
    }

    public function destroyPlan(int $id)
    {
        SslPlan::findOrFail($id)->delete();
        return json(Response::success(null, 'SSL plan deleted'));
    }

    public function certs()
    {
        $certs = SslCertificate::with('resource')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($c) => [
                'id'             => $c->id,
                'resource_id'    => $c->resource_id,
                'domain_name'    => $c->domain_name,
                'cert_type'      => $c->cert_type,
                'status'         => $c->status,
                'issuer'         => $c->issuer,
                'issued_at'      => $c->issued_at,
                'expires_at'     => $c->expires_at,
                'auto_renew'     => $c->auto_renew,
                'user_id'        => $c->resource->user_id ?? null,
            ]);

        return json(Response::success($certs));
    }

    public function revokeCert($request, int $id)
    {
        $cert = SslCertificate::findOrFail($id);
        $cert->update(['status' => 'revoked']);
        return json(Response::success(null, 'Certificate revoked'));
    }
}
