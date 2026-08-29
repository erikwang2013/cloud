<?php
namespace App\cdn\provider;

use App\cdn\model\ResourceCdn;
use GuzzleHttp\Client;

class CloudflareAdapter implements CdnAdapterInterface
{
    private string $token;
    private string $zoneId;
    private Client $http;

    public function __construct(array $credentials = [])
    {
        $this->token  = (string) ($credentials['api_key'] ?? '') ?: (string) getenv('CLOUDFLARE_API_TOKEN');
        $this->zoneId = (string) ($credentials['config']['zone_id'] ?? '') ?: (string) getenv('CLOUDFLARE_ZONE_ID');
        if ($this->token === '' || $this->zoneId === '') {
            throw new CdnAdapterException('Cloudflare credentials missing', CdnAdapterException::REASON_CREDENTIAL);
        }
        $this->http = new Client(['base_uri' => 'https://api.cloudflare.com/client/v4/', 'timeout' => 30, 'connect_timeout' => 10]);
    }

    public function requiresIcpRegistration(): bool
    {
        return false;
    }

    public function createDomain(ResourceCdn $cdn): array
    {
        $data = $this->request('POST', "zones/{$this->zoneId}/custom_hostnames", [
            'hostname' => $cdn->cdn_domain,
            'ssl'      => ['method' => 'txt', 'type' => 'dv'],
        ]);
        return ['provider_domain_id' => $data['result']['id'] ?? null, 'zone_id' => $this->zoneId];
    }

    public function configureDomain(ResourceCdn $cdn): array
    {
        return [];
    }

    public function purgeCache(ResourceCdn $cdn, array $urls): array
    {
        $purged = 0;
        // 单次 purge_cache 请求最多 30 个 URL，超出分批
        foreach (array_chunk($urls, 30) as $chunk) {
            $this->request('POST', "zones/{$this->zoneId}/purge_cache", ['files' => $chunk]);
            $purged += count($chunk);
        }
        return ['purged' => $purged];
    }

    public function disableDomain(ResourceCdn $cdn): array
    {
        if ($cdn->provider_domain_id) {
            $this->request('DELETE', "zones/{$this->zoneId}/custom_hostnames/{$cdn->provider_domain_id}");
        }
        return [];
    }

    private function request(string $method, string $path, array $body = []): array
    {
        try {
            $response = $this->http->request($method, $path, [
                'headers' => ['Authorization' => "Bearer {$this->token}"],
                'json'    => $body,
            ]);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw new CdnAdapterException('Cloudflare API error: ' . $e->getMessage());
        }
        $data = json_decode((string) $response->getBody(), true) ?: [];
        if (empty($data['success'])) {
            throw new CdnAdapterException('Cloudflare API error: ' . ($data['errors'][0]['message'] ?? 'unknown'));
        }
        return $data;
    }
}
