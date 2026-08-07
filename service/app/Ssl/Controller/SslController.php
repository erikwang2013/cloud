<?php
namespace App\Ssl\Controller;

use App\Ssl\Model\SslCertificate;
use App\Ssl\Model\SslPlan;
use Common\Helper\Response;

class SslController
{
    public function index($request)
    {
        $userId = $request->userId;
        $certs = SslCertificate::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->orderBy('created_at', 'desc')->get();

        return json(Response::success($certs));
    }

    public function show($request, int $id)
    {
        $userId = $request->userId;
        $cert = SslCertificate::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->findOrFail($id);

        $data = $cert->toArray();
        unset($data['private_key_encrypted']);

        return json(Response::success($data));
    }

    public function downloadCert($request, int $id)
    {
        $userId = $request->userId;
        $cert = SslCertificate::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->findOrFail($id);

        if (!$cert->cert_pem) {
            return json(Response::error(400, 'Certificate not yet issued'));
        }

        return json(Response::success([
            'cert_pem'  => $cert->cert_pem,
            'csr'       => $cert->csr,
            'domain'    => $cert->domain_name,
            'expires_at' => $cert->expires_at,
        ]));
    }

    public function downloadKey($request, int $id)
    {
        $userId = $request->userId;
        $cert = SslCertificate::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->findOrFail($id);

        if (!$cert->private_key_encrypted) {
            return json(Response::error(400, 'Private key not available'));
        }

        return json(Response::success([
            'private_key' => $cert->private_key_encrypted,
            'domain'      => $cert->domain_name,
        ]));
    }

    public function toggleAutoRenew($request, int $id)
    {
        $userId = $request->userId;
        $cert = SslCertificate::whereHas('resource', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->findOrFail($id);

        $cert->update(['auto_renew' => !$cert->auto_renew]);

        return json(Response::success([
            'auto_renew' => $cert->auto_renew,
        ], $cert->auto_renew ? 'Auto-renew enabled' : 'Auto-renew disabled'));
    }

    public function plans()
    {
        $plans = SslPlan::where('status', 'active')->get();
        return json(Response::success($plans));
    }
}
