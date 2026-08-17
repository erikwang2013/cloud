<?php

namespace App\Grpc;

use GuzzleHttp\Client;

/**
 * Minimal etcd v3 HTTP JSON-API registry — mirrors the key layout of the Rust
 * ecat-registry-etcd crate: /ecat/services/{prefix}/{name}/{uuid}.
 */
class EtcdRegistry
{
    private Client $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $prefix = 'cloud',
        private readonly int $leaseTtl = 15,
    ) {
        $this->http = new Client(['timeout' => 3.0]);
    }

    /**
     * Grant a lease and put the instance key. Returns the lease ID.
     */
    public function register(string $name, string $version, array $endpoints = [], array $metadata = []): int
    {
        $leaseId = $this->grant();
        $key = sprintf('/ecat/services/%s/%s/%s', $this->prefix, $name, uniqid('', true));
        $value = json_encode([
            'name' => $name,
            'version' => $version,
            'endpoints' => array_values($endpoints),
            'metadata' => $metadata,
        ], JSON_UNESCAPED_UNICODE);
        $this->post('/v3/kv/put', [
            'key' => base64_encode($key),
            'value' => base64_encode($value),
            'lease' => (string) $leaseId,
        ]);
        return $leaseId;
    }

    public function keepalive(int $leaseId): void
    {
        $this->post('/v3/lease/keepalive', ['ID' => (string) $leaseId]);
    }

    /**
     * @return array<int, array{name:string,version:string,endpoints:array,metadata:array}>
     */
    public function discover(string $name): array
    {
        $prefix = sprintf('/ecat/services/%s/%s/', $this->prefix, $name);
        $resp = $this->post('/v3/kv/range', [
            'key' => base64_encode($prefix),
            'range_end' => base64_encode($this->prefixEnd($prefix)),
        ]);
        $instances = [];
        foreach ($resp['kvs'] ?? [] as $kv) {
            $decoded = json_decode(base64_decode($kv['value'] ?? ''), true);
            if (is_array($decoded)) {
                $instances[] = $decoded;
            }
        }
        return $instances;
    }

    public function deregister(string $name): void
    {
        $prefix = sprintf('/ecat/services/%s/%s/', $this->prefix, $name);
        $this->post('/v3/kv/deleterange', [
            'key' => base64_encode($prefix),
            'range_end' => base64_encode($this->prefixEnd($prefix)),
        ]);
    }

    private function grant(): int
    {
        $resp = $this->post('/v3/lease/grant', ['TTL' => (string) $this->leaseTtl]);
        return (int) ($resp['ID'] ?? 0);
    }

    private function prefixEnd(string $prefix): string
    {
        $bytes = array_values(unpack('C*', $prefix));
        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            if ($bytes[$i] < 0xff) {
                $bytes[$i]++;
                return pack('C*', ...array_slice($bytes, 0, $i + 1));
            }
        }
        return $prefix;
    }

    private function post(string $path, array $body): array
    {
        $resp = $this->http->post($this->baseUrl . $path, ['json' => $body]);
        return json_decode((string) $resp->getBody(), true) ?: [];
    }
}
