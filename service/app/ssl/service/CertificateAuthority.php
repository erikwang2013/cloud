<?php
namespace App\ssl\service;

class CertificateAuthority
{
    private string $provider;
    private ?string $apiKey;
    private ?string $apiSecret;
    private bool $staging;

    public function __construct(string $provider = 'letsencrypt', ?string $apiKey = null, ?string $apiSecret = null, bool $staging = false)
    {
        $this->provider  = $provider;
        // 未显式传入 API key 时，按提供商从环境变量读取默认值
        $this->apiKey    = $apiKey ?? match ($provider) {
            'zerossl'  => getenv('SSL_ZEROSSL_API_KEY') ?: null,
            'gogetssl' => getenv('SSL_GOGETSSL_API_KEY') ?: null,
            default    => null,
        };
        $this->apiSecret = $apiSecret;
        $this->staging   = $staging;
    }

    public function issue(string $domain, string $certType, bool $wildcard, string $validationMethod): array
    {
        return match ($this->provider) {
            'letsencrypt' => $this->issueAcme($domain, $wildcard, $validationMethod),
            'zerossl'     => $this->issueViaApi($domain, $certType, $wildcard, $validationMethod, 'https://api.zerossl.com'),
            'gogetssl'    => $this->issueViaApi($domain, $certType, $wildcard, $validationMethod, 'https://api.gogetssl.com'),
            default       => throw new \RuntimeException("Unknown CA provider: {$this->provider}"),
        };
    }

    public function renew(string $domain, string $certType, bool $wildcard, string $validationMethod): array
    {
        return $this->issue($domain, $certType, $wildcard, $validationMethod);
    }

    public function revoke(string $certPem): bool
    {
        if ($this->provider === 'letsencrypt') {
            return $this->revokeAcme($certPem);
        }
        return true;
    }

    private function issueAcme(string $domain, bool $wildcard, string $validationMethod): array
    {
        $directory = $this->staging
            ? 'https://acme-staging-v02.api.letsencrypt.org/directory'
            : 'https://acme-v02.api.letsencrypt.org/directory';

        $subject = $wildcard ? "*.$domain" : $domain;

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);

        $csr = openssl_csr_new([
            'commonName' => $subject,
        ], $key, ['digest_alg' => 'sha256']);

        openssl_csr_export($csr, $csrOut);

        return [
            'csr'             => $csrOut,
            'private_key'     => $privateKey,
            'subject'         => $subject,
            'validation_method' => $validationMethod,
            'ca_provider'     => 'letsencrypt',
            'staging'         => $this->staging,
        ];
    }

    private function issueViaApi(string $domain, string $certType, bool $wildcard, string $validationMethod, string $endpoint): array
    {
        if (empty($this->apiKey)) {
            $envName = $this->provider === 'zerossl' ? 'SSL_ZEROSSL_API_KEY' : 'SSL_GOGETSSL_API_KEY';
            throw new \RuntimeException(
                "CA provider '{$this->provider}' not configured: missing {$envName}"
            );
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);

        $subject = $wildcard ? "*.$domain" : $domain;
        $csr = openssl_csr_new([
            'commonName' => $subject,
        ], $key, ['digest_alg' => 'sha256']);
        openssl_csr_export($csr, $csrOut);

        $client = new \GuzzleHttp\Client(['timeout' => 30]);
        $response = $client->post("{$endpoint}/v1/orders", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'domain'           => $domain,
                'csr'              => $csrOut,
                'cert_type'        => $certType,
                'wildcard'         => $wildcard,
                'validation_method' => $validationMethod,
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);

        return [
            'csr'         => $csrOut,
            'private_key' => $privateKey,
            'subject'     => $subject,
            'cert_pem'    => $body['certificate'] ?? null,
            'issuer'      => $body['issuer'] ?? null,
            'issued_at'   => $body['issued_at'] ?? date('Y-m-d H:i:s'),
            'expires_at'  => $body['expires_at'] ?? null,
            'order_id'    => $body['order_id'] ?? null,
            'validation_method' => $validationMethod,
            'ca_provider' => $this->provider,
        ];
    }

    private function revokeAcme(string $certPem): bool
    {
        $directory = $this->staging
            ? 'https://acme-staging-v02.api.letsencrypt.org/directory'
            : 'https://acme-v02.api.letsencrypt.org/directory';

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $client->post("{$directory}/revoke", [
                'json' => ['certificate' => base64_encode($certPem)],
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
