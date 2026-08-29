<?php
namespace App\cdn\provider;

use App\provisioning\model\ProviderApi;

class CdnAdapterFactory
{
    public const TYPES = ['cloudflare', 'cloudfront', 'aliyun', 'tencent'];

    public static function create(string $providerType, array $credentials = []): CdnAdapterInterface
    {
        return match ($providerType) {
            'cloudflare' => new CloudflareAdapter($credentials),
            'cloudfront' => new CloudFrontAdapter($credentials),
            'aliyun'     => new AliyunCdnAdapter($credentials),
            'tencent'    => new TencentCdnAdapter($credentials),
            default      => throw new \InvalidArgumentException("Unsupported CDN provider: {$providerType}"),
        };
    }

    /**
     * 解析适配器与账号：优先按 provider_account_id，否则按 code=cdn-{type} 的活动账号，env 为最后 fallback。
     * @param bool $strict 严格快照模式：账号缺失/禁用直接抛 credential_missing，不做 code fallback
     */
    public static function resolve(string $providerType, ?int $accountId = null, bool $strict = false): array
    {
        $account = null;
        if ($accountId) {
            $account = ProviderApi::where('id', $accountId)->where('status', 'active')->first();
        }
        if (!$account && !$strict) {
            $account = ProviderApi::where('code', 'cdn-' . $providerType)->where('status', 'active')->first();
        }
        if (!$account && $strict) {
            throw new CdnAdapterException('CDN account missing or disabled', CdnAdapterException::REASON_CREDENTIAL);
        }
        $credentials = $account ? [
            'api_key'    => $account->api_key_encrypted,
            'api_secret' => $account->api_secret_encrypted,
            'config'     => $account->config ?? [],
        ] : [];
        return [self::create($providerType, $credentials), $account ? (int) $account->id : null];
    }
}
