<?php
namespace App\Ssl\Service;

use App\Provisioning\Service\ProviderInterface;
use App\Provisioning\Model\ProvisionTask;
use App\Provisioning\Model\ProvisionResult;
use App\Provisioning\Model\Resource;
use App\Provisioning\Model\ResourceStatus;
use App\Ssl\Model\SslCertificate;

class SslProvider implements ProviderInterface
{
    private ?CertificateAuthority $ca = null;

    private function getCa(SslCertificate $cert): CertificateAuthority
    {
        if ($this->ca) return $this->ca;
        $plan = \App\Ssl\Model\SslPlan::find($cert->cert_type) ?? new \App\Ssl\Model\SslPlan();
        $this->ca = new CertificateAuthority(
            $cert->cert_type === 'DV' ? 'letsencrypt' : ($plan->ca_provider ?? 'letsencrypt'),
            null, null,
            (bool) getenv('SSL_STAGING')
        );
        return $this->ca;
    }

    public function create(ProvisionTask $task): ProvisionResult
    {
        $params = json_decode($task->params, true);
        $domain      = $params['domain'] ?? '';
        $certType    = $params['cert_type'] ?? 'DV';
        $wildcard    = $params['wildcard'] ?? false;
        $validation  = $params['validation_method'] ?? 'http-01';

        try {
            $ca = new CertificateAuthority(
                $certType === 'DV' ? 'letsencrypt' : 'zerossl',
                null, null,
                (bool) getenv('SSL_STAGING')
            );
            $result = $ca->issue($domain, $certType, $wildcard, $validation);

            SslCertificate::where('resource_id', $task->resource_id)->update([
                'csr'                    => $result['csr'] ?? null,
                'private_key_encrypted'  => $result['private_key'] ?? null,
                'cert_pem'               => $result['cert_pem'] ?? null,
                'issuer'                 => $result['issuer'] ?? null,
                'status'                 => $result['cert_pem'] ? 'issued' : 'issuing',
                'issued_at'              => $result['issued_at'] ?? date('Y-m-d H:i:s'),
                'expires_at'             => $result['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+90 days')),
                'validation_method'      => $validation,
            ]);

            return ProvisionResult::success($result);
        } catch (\Throwable $e) {
            return ProvisionResult::retryable('SSL issuance failed: ' . $e->getMessage());
        }
    }

    public function renew(Resource $resource, int $months): ProvisionResult
    {
        $cert = SslCertificate::where('resource_id', $resource->id)->first();
        if (!$cert) {
            return ProvisionResult::failed('SSL certificate record not found');
        }

        try {
            $ca = $this->getCa($cert);
            $result = $ca->renew($cert->domain_name, $cert->cert_type, $cert->wildcard, $cert->validation_method);

            $cert->update([
                'csr'                   => $result['csr'] ?? $cert->csr,
                'private_key_encrypted' => $result['private_key'] ?? $cert->private_key_encrypted,
                'cert_pem'              => $result['cert_pem'] ?? null,
                'issuer'                => $result['issuer'] ?? $cert->issuer,
                'status'                => $result['cert_pem'] ? 'issued' : 'issuing',
                'issued_at'             => $result['issued_at'] ?? date('Y-m-d H:i:s'),
                'expires_at'            => $result['expires_at'] ?? date('Y-m-d H:i:s', strtotime("+{$cert->validity_days} days")),
                'last_checked_at'       => date('Y-m-d H:i:s'),
            ]);

            return ProvisionResult::success($result);
        } catch (\Throwable $e) {
            $cert->update(['status' => 'failed', 'last_checked_at' => date('Y-m-d H:i:s')]);
            return ProvisionResult::retryable('SSL renew failed: ' . $e->getMessage());
        }
    }

    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult
    {
        return ProvisionResult::success(['message' => 'SSL upgrade processed']);
    }

    public function destroy(Resource $resource): ProvisionResult
    {
        $cert = SslCertificate::where('resource_id', $resource->id)->first();
        if ($cert && $cert->cert_pem) {
            try {
                $ca = $this->getCa($cert);
                $ca->revoke($cert->cert_pem);
            } catch (\Throwable) {}
            $cert->update(['status' => 'revoked']);
        }
        return ProvisionResult::success([]);
    }

    public function status(Resource $resource): ResourceStatus
    {
        $cert  = SslCertificate::where('resource_id', $resource->id)->first();
        $rs    = new ResourceStatus();
        $rs->status  = $cert ? $cert->status : 'unknown';
        $rs->metrics = [];
        return $rs;
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
}
