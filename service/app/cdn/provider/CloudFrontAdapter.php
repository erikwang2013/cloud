<?php
namespace App\cdn\provider;

use App\cdn\model\ResourceCdn;
use Aws\CloudFront\CloudFrontClient;

class CloudFrontAdapter implements CdnAdapterInterface
{
    private CloudFrontClient $client;

    public function __construct(array $credentials = [])
    {
        $key    = (string) ($credentials['api_key'] ?? '') ?: (string) getenv('AWS_ACCESS_KEY_ID');
        $secret = (string) ($credentials['api_secret'] ?? '') ?: (string) getenv('AWS_SECRET_ACCESS_KEY');
        if ($key === '' || $secret === '') {
            throw new CdnAdapterException('AWS credentials missing', CdnAdapterException::REASON_CREDENTIAL);
        }
        // CloudFront 为全局服务，SDK 仍强制要求 region 字段，固定 us-east-1
        $this->client = new CloudFrontClient([
            'version'     => 'latest',
            'region'      => 'us-east-1',
            'credentials' => ['key' => $key, 'secret' => $secret],
            'http'        => ['timeout' => 30, 'connect_timeout' => 10],
        ]);
    }

    public function requiresIcpRegistration(): bool
    {
        return false;
    }

    public function createDomain(ResourceCdn $cdn): array
    {
        $origin = $cdn->origin_type === 'storage'
            ? ['Id' => 'origin-1', 'DomainName' => $cdn->origin_value, 'S3OriginConfig' => ['OriginAccessIdentity' => '']]
            : ['Id' => 'origin-1', 'DomainName' => $cdn->origin_value,
               'CustomOriginConfig' => ['HTTPPort' => 80, 'HTTPSPort' => 443, 'OriginProtocolPolicy' => 'https-only']];

        $result = $this->client->createDistribution([
            'DistributionConfig' => [
                'CallerReference' => uniqid('cdn-', true),
                'Comment'         => $cdn->cdn_domain,
                'Enabled'         => true,
                'Aliases'         => ['Quantity' => 1, 'Items' => [$cdn->cdn_domain]],
                'Origins'         => ['Quantity' => 1, 'Items' => [$origin]],
                'DefaultCacheBehavior' => [
                    'TargetOriginId'       => 'origin-1',
                    'ViewerProtocolPolicy' => 'redirect-to-https',
                    'MinTTL'               => 0,
                    'ForwardedValues'      => [
                        'QueryString' => true,
                        'Cookies'     => ['Forward' => 'all'],
                        'Headers'     => ['Quantity' => 0, 'Items' => []],
                    ],
                    'TrustedSigners' => ['Enabled' => false, 'Quantity' => 0],
                    'AllowedMethods' => ['Quantity' => 2, 'Items' => ['GET', 'HEAD']],
                ],
            ],
        ]);
        return ['provider_domain_id' => $result['Distribution']['Id'] ?? null];
    }

    public function configureDomain(ResourceCdn $cdn): array
    {
        return [];
    }

    public function purgeCache(ResourceCdn $cdn, array $urls): array
    {
        $paths = array_map([self::class, 'toPath'], $urls);
        $this->client->createInvalidation([
            'DistributionId'   => $cdn->provider_domain_id,
            'InvalidationBatch' => [
                'CallerReference' => uniqid('inv-', true),
                'Paths'           => ['Quantity' => count($paths), 'Items' => $paths],
            ],
        ]);
        return ['purged' => count($urls)];
    }

    public function disableDomain(ResourceCdn $cdn): array
    {
        if (!$cdn->provider_domain_id) {
            return [];
        }
        // 删除前必须先停用：先 disable 再 delete（delete 要求 Enabled=false）
        $dist = $this->client->getDistribution(['Id' => $cdn->provider_domain_id]);
        $config = $dist['Distribution']['DistributionConfig'];
        if ($config['Enabled']) {
            $config['Enabled'] = false;
            $this->client->updateDistribution([
                'Id'                 => $cdn->provider_domain_id,
                'IfMatch'            => $dist['ETag'],
                'DistributionConfig' => $config,
            ]);
            $dist = $this->client->getDistribution(['Id' => $cdn->provider_domain_id]);
        }
        $this->client->deleteDistribution(['Id' => $cdn->provider_domain_id, 'IfMatch' => $dist['ETag']]);
        return [];
    }

    /** 完整 URL（https://cdn.example.com/a?b=1）转为 CloudFront 失效路径（/a?b=1） */
    private static function toPath(string $url): string
    {
        $path  = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        return $query !== null ? $path . '?' . $query : $path;
    }
}
