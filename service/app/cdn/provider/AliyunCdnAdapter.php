<?php
namespace App\cdn\provider;

use App\cdn\model\ResourceCdn;
use GuzzleHttp\Client;

class AliyunCdnAdapter implements CdnAdapterInterface
{
    private const ENDPOINT = 'https://cdn.aliyuncs.com/';
    private const VERSION = '2018-05-10';

    private string $accessKeyId;
    private string $accessKeySecret;
    private Client $http;

    public function __construct(array $credentials = [])
    {
        $this->accessKeyId     = (string) ($credentials['api_key'] ?? '') ?: (string) getenv('ALIYUN_CDN_ACCESS_KEY_ID');
        $this->accessKeySecret = (string) ($credentials['api_secret'] ?? '') ?: (string) getenv('ALIYUN_CDN_ACCESS_KEY_SECRET');
        if ($this->accessKeyId === '' || $this->accessKeySecret === '') {
            throw new CdnAdapterException('Aliyun CDN credentials missing', CdnAdapterException::REASON_CREDENTIAL);
        }
        $this->http = new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }

    public function requiresIcpRegistration(): bool
    {
        return true;
    }

    public function createDomain(ResourceCdn $cdn): array
    {
        $sources = json_encode([[
            'type'     => $cdn->origin_type === 'storage' ? 'oss' : 'domain',
            'content'  => $cdn->origin_value,
            'port'     => 443,
            'priority' => 20,
        ]], JSON_UNESCAPED_UNICODE);
        $this->rpc('AddCdnDomain', [
            'DomainName' => $cdn->cdn_domain,
            'CdnType'    => 'web',
            'Sources'    => $sources,
            'Scope'      => 'domestic',
        ]);
        return [];
    }

    public function configureDomain(ResourceCdn $cdn): array
    {
        $cert = $cdn->cert_config ?? [];
        if (empty($cert['cert']) || empty($cert['key'])) {
            return [];
        }
        $this->rpc('SetCdnDomainSSLCertificate', [
            'DomainName' => $cdn->cdn_domain,
            'SSLProtocol' => 'on',
            'CertName'    => $cert['name'] ?? $cdn->cdn_domain,
            'SSLPub'      => $cert['cert'],
            'SSLPri'      => $cert['key'],
        ]);
        return [];
    }

    public function purgeCache(ResourceCdn $cdn, array $urls): array
    {
        $this->rpc('RefreshObjectCaches', [
            'ObjectPath' => implode(',', $urls),
            'ObjectType' => 'File',
        ]);
        return ['purged' => count($urls)];
    }

    public function disableDomain(ResourceCdn $cdn): array
    {
        $this->rpc('DeleteCdnDomain', ['DomainName' => $cdn->cdn_domain]);
        return [];
    }

    private function rpc(string $action, array $params): array
    {
        $query = $params + [
            'Action'           => $action,
            'Version'          => self::VERSION,
            'Format'           => 'JSON',
            'AccessKeyId'      => $this->accessKeyId,
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce'   => uniqid('', true),
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $query['Signature'] = self::sign($query, $this->accessKeySecret);

        try {
            $response = $this->http->post(self::ENDPOINT, ['form_params' => $query]);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw new CdnAdapterException('Aliyun CDN API error: ' . $e->getMessage());
        }
        $data = json_decode((string) $response->getBody(), true) ?: [];
        if (isset($data['Code'])) {
            $reason = CdnAdapterException::icpReason((string) $data['Code'], (string) ($data['Message'] ?? ''));
            throw new CdnAdapterException('Aliyun CDN API error: ' . ($data['Message'] ?? 'unknown'), $reason);
        }
        return $data;
    }

    /** 经典 RPC 签名（HMAC-SHA1），与阿里云官方示例一致 */
    public static function sign(array $params, string $accessKeySecret, string $method = 'POST'): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = self::percentEncode((string) $key) . '=' . self::percentEncode((string) $value);
        }
        $stringToSign = $method . '&%2F&' . self::percentEncode(implode('&', $parts));
        return base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
    }

    public static function percentEncode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }
}
