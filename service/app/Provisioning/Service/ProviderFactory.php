<?php
namespace App\Provisioning\Service;

use App\Provisioning\Model\ProvisionTask;
use App\Provisioning\Model\Resource;

class ProviderFactory
{
    private array $providers = [];

    public function register(string $productType, string $provider, callable $factory): void
    {
        $this->providers["{$productType}:{$provider}"] = $factory;
    }

    public function create(ProvisionTask $task): ProviderInterface
    {
        $key = "{$task->product_type}:{$task->provider}";

        if (!isset($this->providers[$key])) {
            throw new \RuntimeException("No provider registered for: {$key}");
        }

        return call_user_func($this->providers[$key], $task);
    }

    public function createFromResource(Resource $resource): ProviderInterface
    {
        $key = "{$resource->type}:{$resource->provider}";

        if (!isset($this->providers[$key])) {
            throw new \RuntimeException("No provider registered for: {$key}");
        }

        return call_user_func($this->providers[$key], null);
    }
}
