<?php
namespace App\ssl\service;

use App\provisioning\service\ProviderInterface;
use App\provisioning\model\ProvisionTask;
use App\provisioning\model\ProvisionResult;
use App\provisioning\model\Resource;
use App\provisioning\model\resourcestatus;
use App\ssl\model\SslCertificate;
use support\Log;

class SslProvider implements ProviderInterface
{
    private ?CertificateAuthority $ca = null;

    private function getCa(SslCertificate $cert): CertificateAuthority
    {
        if ($this->ca) return $this->ca;
        // cert_type 是 ssl_plans 的业务列，不是主键，按列查询
        $plan = \App\ssl\model\SslPlan::where('cert_type', $cert->cert_type)->first()
            ?? new \App\ssl\model\SslPlan();
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

            // 通知资源所有者：新签发 / 排队自动续期成功（字面量 code，便于静态回归护栏覆盖）
            if (!empty($result['cert_pem'])) {
                try {
                    $owner = \App\provisioning\model\Resource::find($task->resource_id);
                    if ($owner && $owner->user_id) {
                        $dispatcher = new \App\notification\service\NotificationDispatcher();
                        // action 是任务顶层列（SslCertificateCheck 排队续期置 'renew'），params JSON 内无此键
                        if (($task->action ?? 'create') === 'renew') {
                            $dispatcher->dispatch($owner->user_id, 'ssl_cert_renewed', ['domain' => $domain], ['email', 'in_app']);
                        } else {
                            $dispatcher->dispatch($owner->user_id, 'ssl_cert_issued', ['domain' => $domain], ['email', 'in_app']);
                        }
                    }
                } catch (\Throwable $e) {
                    // 通知非关键，失败不阻断交付结果，避免已签发证书被误判 retryable
                }
            }

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

            // 通知资源所有者：直接续期成功（通知非关键，失败不阻断交付结果）
            if (!empty($result['cert_pem']) && $resource->user_id) {
                try {
                    (new \App\notification\service\NotificationDispatcher())->dispatch(
                        $resource->user_id, 'ssl_cert_renewed',
                        ['domain' => $cert->domain_name],
                        ['email', 'in_app']
                    );
                } catch (\Throwable) {
                    // 忽略通知异常，续期结果已落库
                }
            }

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
            } catch (\Throwable $e) {
                Log::error("SSL certificate revocation failed: resource={$resource->id} cert={$cert->id}: {$e->getMessage()}");
            }
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
