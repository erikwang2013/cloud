<?php
namespace App\cdn\controller;

use App\cdn\model\ResourceCdn;
use App\cdn\provider\CdnAdapterException;
use App\cdn\provider\CdnAdapterFactory;
use App\provisioning\model\Resource;
use Common\helper\Response;
use support\Log;

class CdnController
{
    private const MAX_PURGE_URLS = 100;

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
        $cdn = $this->owned($request, $id);
        return json(Response::success($cdn));
    }

    public function create($request)
    {
        $userId       = $request->userId;
        $domain       = trim((string) $request->input('domain', ''));
        $providerType = (string) $request->input('provider_type', 'cloudflare');
        $originType   = (string) $request->input('origin_type', 'server');
        $originValue  = trim((string) $request->input('origin_value', ''));
        $resourceId   = (int) $request->input('resource_id', 0);
        $certConfig   = $request->input('cert_config');

        if ($domain === '' || $originValue === '' || $resourceId <= 0) {
            return json(Response::error(4001, 'cdn.params_missing'));
        }
        if (!preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $domain)) {
            return json(Response::error(4001, 'cdn.invalid_domain'));
        }
        if (!in_array($providerType, CdnAdapterFactory::TYPES, true)) {
            return json(Response::error(4001, 'cdn.invalid_provider'));
        }
        if ($certConfig !== null && !is_array($certConfig)) {
            return json(Response::error(4001, 'cdn.params_missing'));
        }
        if (is_array($certConfig)) {
            // name 会被适配器读入 CertName 并持久化，一并限长，防 JSON 列塞大值
            foreach (['name', 'cert', 'key'] as $field) {
                if (isset($certConfig[$field]) && strlen((string) $certConfig[$field]) > 65536) {
                    return json(Response::error(4001, 'cdn.params_missing'));
                }
            }
        }
        // 私钥只透传服务商侧，落库前剔除（cert_config 列存非敏感证书信息）
        $storedCert = $certConfig ?: [];
        unset($storedCert['key']);

        $resource = Resource::where('id', $resourceId)->where('user_id', $userId)->first();
        if (!$resource) {
            // 资源不存在或不属于当前用户，一律 404 不泄露存在性
            return json(Response::error(404, 'cdn.domain_not_found'));
        }

        $cdn = ResourceCdn::where('resource_id', $resourceId)->where('cdn_domain', $domain)->first();
        if ($cdn && $cdn->status === 'active') {
            // 幂等返回：用已有域自己的 provider 解析适配器，避免传入不同 provider 时 ICP 提示错误
            $effectiveType = $cdn->provider_type ?: $providerType;
            try {
                [$adapter] = CdnAdapterFactory::resolve($effectiveType, $cdn->provider_account_id);
            } catch (CdnAdapterException $e) {
                return json(Response::error(4003, 'cdn.credential_missing'));
            }
            return json(Response::success($this->present($cdn, $adapter), 'cdn.domain_created'));
        }
        try {
            [$adapter, $accountId] = CdnAdapterFactory::resolve($providerType, $cdn?->provider_account_id);
        } catch (CdnAdapterException $e) {
            return json(Response::error(4003, 'cdn.credential_missing'));
        }
        if (!$cdn) {
            $cdn = new ResourceCdn(['resource_id' => $resourceId]);
        }
        $cdn->fill([
            'cdn_domain'           => $domain,
            'origin_type'          => $originType,
            'origin_value'         => $originValue,
            'provider_type'        => $providerType,
            'provider_account_id'  => $accountId,
            'cert_config'          => $storedCert,
            'status'               => 'pending',
        ]);
        $cdn->save();

        try {
            $result = $adapter->createDomain($cdn);
            if ($certConfig) {
                // configureDomain 需要完整证书（含私钥），仅内存使用；finally 保证异常路径也还原，
                // 否则 catch 里的 update() 会把 dirty 私钥一并写库
                try {
                    $cdn->cert_config = $certConfig;
                    $adapter->configureDomain($cdn);
                } finally {
                    $cdn->cert_config = $storedCert;
                }
            }
            $cdn->provider_domain_id = $result['provider_domain_id'] ?? $cdn->provider_domain_id;
            $cdn->zone_id            = $result['zone_id'] ?? $cdn->zone_id;
            $cdn->status             = 'active';
            $cdn->save();
        } catch (CdnAdapterException $e) {
            $cdn->update(['status' => 'failed']);
            if ($e->reason === CdnAdapterException::REASON_ICP) {
                return json(Response::error(4002, 'cdn.icp_required', [
                    'provider_type'              => $providerType,
                    'requires_icp_registration'  => true,
                ]));
            }
            if ($e->reason === CdnAdapterException::REASON_CREDENTIAL) {
                return json(Response::error(4003, 'cdn.credential_missing'));
            }
            Log::error("CDN create failed: domain={$domain} provider={$providerType}: {$e->getMessage()}");
            return json(Response::error(5001, 'cdn.provider_api_failed'));
        } catch (\Throwable $e) {
            $cdn->update(['status' => 'failed']);
            Log::error("CDN create failed: domain={$domain} provider={$providerType}: {$e->getMessage()}");
            return json(Response::error(5001, 'cdn.provider_api_failed'));
        }

        return json(Response::success($this->present($cdn, $adapter), 'cdn.domain_created'));
    }

    public function destroy($request, int $id)
    {
        $cdn = $this->owned($request, $id);
        if ($cdn->status !== 'deleted') {
            try {
                // 严格快照：禁用只针对创建时绑定的账号，缺失/禁用报 4003，不静默换账号
                [$adapter] = CdnAdapterFactory::resolve($cdn->provider_type, $cdn->provider_account_id, true);
                $adapter->disableDomain($cdn);
                $cdn->update(['status' => 'deleted']);
            } catch (CdnAdapterException $e) {
                if ($e->reason === CdnAdapterException::REASON_CREDENTIAL) {
                    return json(Response::error(4003, 'cdn.credential_missing'));
                }
                return json(Response::error(5001, 'cdn.provider_api_failed'));
            } catch (\Throwable $e) {
                return json(Response::error(5001, 'cdn.provider_api_failed'));
            }
        }
        return json(Response::success(['id' => $id], 'cdn.domain_deleted'));
    }

    public function purgeCache($request, int $id)
    {
        $cdn = $this->owned($request, $id);
        $urls = $request->input('urls', []);
        if (!is_array($urls) || empty($urls)) {
            return json(Response::error(4001, 'cdn.urls_required'));
        }
        $urls = array_values(array_unique(array_map('trim', $urls)));
        if (count($urls) > self::MAX_PURGE_URLS) {
            return json(Response::error(4001, 'cdn.too_many_urls'));
        }
        $domain = strtolower($cdn->cdn_domain);
        foreach ($urls as $url) {
            // 仅允许本域名或其子域，拒绝通配符与外部 URL
            if (!preg_match('#^https?://#i', $url) || str_contains($url, '*')) {
                return json(Response::error(4001, 'cdn.invalid_url'));
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '' || ($host !== $domain && !str_ends_with($host, '.' . $domain))) {
                return json(Response::error(4001, 'cdn.invalid_url'));
            }
        }

        try {
            // 严格快照：purge 只用创建时绑定的账号，缺失/禁用报 4003，不静默换账号
            [$adapter] = CdnAdapterFactory::resolve($cdn->provider_type, $cdn->provider_account_id, true);
            $result = $adapter->purgeCache($cdn, $urls);
            $cdn->update(['purged_at' => date('Y-m-d H:i:s')]);
            return json(Response::success(['purged' => count($urls), 'urls' => $urls] + $result, 'cdn.purge_requested'));
        } catch (CdnAdapterException $e) {
            if ($e->reason === CdnAdapterException::REASON_CREDENTIAL) {
                return json(Response::error(4003, 'cdn.credential_missing'));
            }
            return json(Response::error(4005, 'cdn.purge_failed'));
        } catch (\Throwable $e) {
            return json(Response::error(4005, 'cdn.purge_failed'));
        }
    }

    public function stats($request, int $id)
    {
        $cdn = $this->owned($request, $id);
        return json(Response::success([
            'cdn_domain'    => $cdn->cdn_domain,
            'provider_type' => $cdn->provider_type,
            'plan'          => $cdn->plan,
            'status'        => $cdn->status,
            'purged_at'     => $cdn->purged_at,
        ]));
    }

    private function owned($request, int $id): ResourceCdn
    {
        return ResourceCdn::whereHas('resource', function ($q) use ($request) {
            $q->where('user_id', $request->userId);
        })->findOrFail($id);
    }

    private function present(ResourceCdn $cdn, $adapter): array
    {
        return [
            'id'                        => $cdn->id,
            'resource_id'               => $cdn->resource_id,
            'cdn_domain'                => $cdn->cdn_domain,
            'origin_type'               => $cdn->origin_type,
            'origin_value'              => $cdn->origin_value,
            'provider_type'             => $cdn->provider_type,
            'provider_domain_id'        => $cdn->provider_domain_id,
            'zone_id'                   => $cdn->zone_id,
            'plan'                      => $cdn->plan,
            'ssl'                       => $cdn->ssl,
            'status'                    => $cdn->status,
            'purged_at'                 => $cdn->purged_at,
            'requires_icp_registration' => $adapter->requiresIcpRegistration(),
        ];
    }
}
