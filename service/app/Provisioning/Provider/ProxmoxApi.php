<?php
namespace App\Provisioning\Provider;

use GuzzleHttp\Client;
use App\Provisioning\Model\HostMachine;

class ProxmoxApi
{
    private string $baseUrl;
    private string $token;
    private Client $http;

    public function __construct(HostMachine $host)
    {
        $this->baseUrl = "https://{$host->ip_address}:8006/api2/json";
        $this->token   = $host->api_token_encrypted;
        // 生产环境建议设置 PROXMOX_SSL_VERIFY=1 并配置合法 CA（IP 直连需证书含 IP SAN）
        $this->http    = new Client([
            'verify'  => getenv('PROXMOX_SSL_VERIFY') === '1',
            'timeout' => 30,
        ]);
    }

    public function get(string $path, array $params = []): array
    {
        $response = $this->http->get($this->baseUrl . $path, [
            'headers' => ['Authorization' => "PVEAPIToken={$this->token}"],
            'query'   => $params,
        ]);
        return json_decode($response->getBody(), true)['data'];
    }

    public function post(string $path, array $data = []): array
    {
        $response = $this->http->post($this->baseUrl . $path, [
            'headers'     => ['Authorization' => "PVEAPIToken={$this->token}"],
            'form_params' => $data,
        ]);
        return json_decode($response->getBody(), true)['data'];
    }

    public function put(string $path, array $data = []): array
    {
        $response = $this->http->put($this->baseUrl . $path, [
            'headers'     => ['Authorization' => "PVEAPIToken={$this->token}"],
            'form_params' => $data,
        ]);
        return json_decode($response->getBody(), true)['data'];
    }

    public function delete(string $path): array
    {
        $response = $this->http->delete($this->baseUrl . $path, [
            'headers' => ['Authorization' => "PVEAPIToken={$this->token}"],
        ]);
        return json_decode($response->getBody(), true)['data'];
    }

    public function nextVmid(): int
    {
        $result = $this->get('/cluster/nextid');
        return $result;
    }
}
