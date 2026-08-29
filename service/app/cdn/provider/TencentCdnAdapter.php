<?php
namespace App\cdn\provider;

use App\cdn\model\ResourceCdn;
use GuzzleHttp\Client;

class TencentCdnAdapter implements CdnAdapterInterface
{
    private const ENDPOINT = 'https://cdn.tencentcloudapi.com';
    private const HOST = 'cdn.tencentcloudapi.com';
    private const VERSION = '2018-06-06';
    private const SERVICE = 'cdn';

    private string $secretId;
    private string $secretKey;
    private Client $http;

    public function __construct(array $credentials = [])
    {
        $this->secretId  = (string) ($credentials['api_key'] ?? '') ?: (string) getenv('TENCENT_CDN_SECRET_ID');
        $this->secretKey = (string) ($credentials['api_secret'] ?? '') ?: (string) getenv('TENCENT_CDN_SECRET_KEY');
        if ($this->secretId === '' || $this->secretKey === '') {
            throw new CdnAdapterException('Tencent CDN credentials missing', CdnAdapterException::REASON_CREDENTIAL);
        }
        $this->http = new Client(['base_uri' => self::ENDPOINT, 'timeout' => 30, 'connect_timeout' => 10]);
    }

    public function requiresIcpRegistration(): bool
    {
        return true;
    }

    public function createDomain(ResourceCdn $cdn): array
    {
        $this->call('AddCdnDomain', [
            'Domain' => $cdn->cdn_domain,
            'Origin' => [
                'Origins'    => [$cdn->origin_value],
                'OriginType' => $cdn->origin_type === 'storage' ? 'cos' : 'domain',
            ],
            'Area' => 'mainland',
        ]);
        return [];
    }

    public function configureDomain(ResourceCdn $cdn): array
    {
        $cert = $cdn->cert_config ?? [];
        if (empty($cert['cert']) || empty($cert['key'])) {
            return [];
        }
        $this->call('UpdateDomainConfig', [
            'Domain' => $cdn->cdn_domain,
            'Https'  => [
                'Switch'    => 'on',
                'CertInfo'  => [
                    'Certificate' => $cert['cert'],
                    'PrivateKey'  => $cert['key'],
                ],
            ],
        ]);
        return [];
    }

    public function purgeCache(ResourceCdn $cdn, array $urls): array
    {
        $this->call('PurgeUrlsCache', ['Urls' => array_values($urls)]);
        return ['purged' => count($urls)];
    }

    public function disableDomain(ResourceCdn $cdn): array
    {
        $this->call('DeleteDomain', ['Domain' => $cdn->cdn_domain]);
        return [];
    }

    private function call(string $action, array $payload): array
    {
        $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = self::sign($this->secretId, $this->secretKey, $action, $body, time());

        try {
            $response = $this->http->post('/', ['headers' => $headers, 'body' => $body]);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw new CdnAdapterException('Tencent CDN API error: ' . $e->getMessage());
        }
        $data = json_decode((string) $response->getBody(), true) ?: [];
        if (isset($data['Response']['Error'])) {
            $error = $data['Response']['Error'];
            $reason = CdnAdapterException::icpReason((string) ($error['Code'] ?? ''), (string) ($error['Message'] ?? ''));
            throw new CdnAdapterException('Tencent CDN API error: ' . ($error['Message'] ?? 'unknown'), $reason);
        }
        return $data['Response'] ?? $data;
    }

    /** TC3-HMAC-SHA256 签名，返回完整请求头（含 Authorization） */
    public static function sign(string $secretId, string $secretKey, string $action, string $payload, int $timestamp): array
    {
        $date       = gmdate('Y-m-d', $timestamp);
        $actionLow  = strtolower($action);
        $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:" . self::HOST . "\nx-tc-action:{$actionLow}\n";
        $signedHeaders    = 'content-type;host;x-tc-action';
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', $payload);
        $credentialScope  = "{$date}/" . self::SERVICE . '/tc3_request';
        $stringToSign     = "TC3-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $secretDate    = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('sha256', self::SERVICE, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature     = hash_hmac('sha256', $stringToSign, $secretSigning);

        return [
            'Authorization' => "TC3-HMAC-SHA256 Credential={$secretId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
            'X-TC-Action'   => $action,
            'X-TC-Version'  => self::VERSION,
            'X-TC-Timestamp' => (string) $timestamp,
            'Content-Type'  => 'application/json; charset=utf-8',
        ];
    }
}
