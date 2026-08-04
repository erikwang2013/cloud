<?php
namespace App\Provisioning\Service;

use App\Provisioning\Model\ProvisionTask;
use App\Provisioning\Model\Resource;
use App\Provisioning\Model\ProviderApi;

class ProviderFactory
{
    private static array $providers = [];

    /**
     * Register default providers. Called once from bootstrap.
     */
    public static function registerDefaults(): void
    {
        self::register('server', 'proxmox', fn() => new \App\Provisioning\Provider\ProxmoxProvider());
        self::register('disk', 'proxmox', fn() => new \App\Provisioning\Provider\ProxmoxProvider());
        self::register('ip', 'proxmox', fn() => new \App\Provisioning\Provider\ProxmoxProvider());

        // SSL certificate providers
        self::register('ssl', 'letsencrypt', fn() => new \App\Ssl\Service\SslProvider());
        self::register('ssl', 'zerossl', fn() => new \App\Ssl\Service\SslProvider());

        if (getenv('AWS_ACCESS_KEY_ID')) {
            $cfg = new ProviderApi([
                'name' => 'AWS EC2 (env)', 'code' => 'aws-ec2',
                'api_key_encrypted' => getenv('AWS_ACCESS_KEY_ID'),
                'api_secret_encrypted' => getenv('AWS_SECRET_ACCESS_KEY'),
            ]);
            $ec2 = fn() => new \App\Provisioning\Provider\AwsEc2Provider($cfg);
            self::register('server', 'aws-ec2', $ec2);
            self::register('disk', 'aws-ec2', $ec2);
            self::register('ip', 'aws-ec2', $ec2);
        }
    }

    /**
     * Clear all registered providers. Used in tests.
     */
    public static function clear(): void
    {
        self::$providers = [];
    }

    public static function register(string $productType, string $provider, callable $factory): void
    {
        self::$providers["{$productType}:{$provider}"] = $factory;
    }

    public function create(ProvisionTask $task): ProviderInterface
    {
        $key = "{$task->product_type}:{$task->provider}";

        if (!isset(self::$providers[$key])) {
            throw new \RuntimeException("No provider registered for: {$key}");
        }

        return call_user_func(self::$providers[$key], $task);
    }

    public function createFromResource(Resource $resource): ProviderInterface
    {
        $key = "{$resource->type}:{$resource->provider}";

        if (!isset(self::$providers[$key])) {
            throw new \RuntimeException("No provider registered for: {$key}");
        }

        $task = new ProvisionTask([
            'product_type' => $resource->type,
            'provider'     => $resource->provider,
        ]);
        return call_user_func(self::$providers[$key], $task);
    }
}
