<?php

namespace Tests\Provisioning;

use App\Provisioning\Model\ProvisionTask;
use App\Provisioning\Model\Resource;
use App\Provisioning\Service\ProviderFactory;
use App\Provisioning\Service\ProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderFactoryTest extends TestCase
{
    private ProviderFactory $factory;

    protected function setUp(): void
    {
        ProviderFactory::clear();
        $this->factory = new ProviderFactory();
    }

    protected function tearDown(): void
    {
        ProviderFactory::clear();
    }

    public function testRegisterAndCreateProvider(): void
    {
        $calledWith = null;
        ProviderFactory::register('server', 'proxmox', function (ProvisionTask $task) use (&$calledWith) {
            $calledWith = $task;
            return new FakeProvider();
        });

        $task = new ProvisionTask(['product_type' => 'server', 'provider' => 'proxmox']);
        $provider = $this->factory->create($task);

        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame($task, $calledWith);
    }

    public function testCreateProviderWithCorrectKey(): void
    {
        ProviderFactory::register('server', 'proxmox', fn() => new FakeProvider());
        ProviderFactory::register('disk', 'proxmox', fn() => new FakeProvider());

        $serverTask = new ProvisionTask(['product_type' => 'server', 'provider' => 'proxmox']);
        $diskTask = new ProvisionTask(['product_type' => 'disk', 'provider' => 'proxmox']);

        $this->assertInstanceOf(FakeProvider::class, $this->factory->create($serverTask));
        $this->assertInstanceOf(FakeProvider::class, $this->factory->create($diskTask));
    }

    public function testCreateThrowsForUnregisteredProvider(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No provider registered for: server:unknown');

        $task = new ProvisionTask(['product_type' => 'server', 'provider' => 'unknown']);
        $this->factory->create($task);
    }

    public function testCreateThrowsForUnregisteredProductType(): void
    {
        ProviderFactory::register('server', 'proxmox', fn() => new FakeProvider());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No provider registered for: disk:proxmox');

        $task = new ProvisionTask(['product_type' => 'disk', 'provider' => 'proxmox']);
        $this->factory->create($task);
    }

    public function testCreateFromResource(): void
    {
        ProviderFactory::register('server', 'proxmox', fn($task) => new FakeProvider());

        $resource = new Resource(['type' => 'server', 'provider' => 'proxmox']);
        $provider = $this->factory->createFromResource($resource);

        $this->assertInstanceOf(ProviderInterface::class, $provider);
    }

    public function testCreateFromResourceThrowsForUnknownKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No provider registered for: unknown:unknown');

        $resource = new Resource(['type' => 'unknown', 'provider' => 'unknown']);
        $this->factory->createFromResource($resource);
    }

    public function testMultipleProvidersForSameType(): void
    {
        $proxmoxCalled = false;
        $aliyunCalled = false;

        ProviderFactory::register('server', 'proxmox', function () use (&$proxmoxCalled) {
            $proxmoxCalled = true;
            return new FakeProvider();
        });
        ProviderFactory::register('server', 'aliyun', function () use (&$aliyunCalled) {
            $aliyunCalled = true;
            return new FakeProvider();
        });

        $this->factory->create(new ProvisionTask(['product_type' => 'server', 'provider' => 'proxmox']));
        $this->assertTrue($proxmoxCalled);
        $this->assertFalse($aliyunCalled);

        $this->factory->create(new ProvisionTask(['product_type' => 'server', 'provider' => 'aliyun']));
        $this->assertTrue($aliyunCalled);
    }

    public function testRegisterOverwritesExistingProvider(): void
    {
        ProviderFactory::register('server', 'proxmox', fn() => new FakeProvider());
        ProviderFactory::register('server', 'proxmox', fn() => new FakeProvider('v2'));

        $task = new ProvisionTask(['product_type' => 'server', 'provider' => 'proxmox']);
        $provider = $this->factory->create($task);

        $this->assertSame('v2', $provider->version);
    }

    #[DataProvider('providerKeyProvider')]
    public function testFactoryKeysCombineTypeAndProvider(string $type, string $provider, string $expectedKey): void
    {
        ProviderFactory::register($type, $provider, fn() => new FakeProvider());

        $task = new ProvisionTask(['product_type' => $type, 'provider' => $provider]);
        $result = $this->factory->create($task);

        $this->assertInstanceOf(FakeProvider::class, $result);
    }

    public static function providerKeyProvider(): array
    {
        return [
            'server proxmox' => ['server', 'proxmox', 'server:proxmox'],
            'disk proxmox'   => ['disk', 'proxmox', 'disk:proxmox'],
            'ip proxmox'     => ['ip', 'proxmox', 'ip:proxmox'],
            'domain aliyun'  => ['domain', 'aliyun', 'domain:aliyun'],
        ];
    }
}

final class FakeProvider implements ProviderInterface
{
    public string $version;

    public function __construct(string $version = 'v1')
    {
        $this->version = $version;
    }

    public function create(ProvisionTask $task): \App\Provisioning\Model\ProvisionResult
    {
        return new \App\Provisioning\Model\ProvisionResult();
    }

    public function renew(Resource $resource, int $months): \App\Provisioning\Model\ProvisionResult
    {
        return new \App\Provisioning\Model\ProvisionResult();
    }

    public function upgrade(Resource $resource, array $newSpecs): \App\Provisioning\Model\ProvisionResult
    {
        return new \App\Provisioning\Model\ProvisionResult();
    }

    public function destroy(Resource $resource): \App\Provisioning\Model\ProvisionResult
    {
        return new \App\Provisioning\Model\ProvisionResult();
    }

    public function status(Resource $resource): \App\Provisioning\Model\ResourceStatus
    {
        return new \App\Provisioning\Model\ResourceStatus();
    }

    public function consoleUrl(Resource $resource): string
    {
        return 'https://console.example.com';
    }

    public function resizeDisk(Resource $resource, int $newSizeGb): \App\Provisioning\Model\ProvisionResult
    {
        return new \App\Provisioning\Model\ProvisionResult();
    }

    public function createDisk(ProvisionTask $task): \App\Provisioning\Model\ProvisionResult
    {
        return new \App\Provisioning\Model\ProvisionResult();
    }

    public function createIp(ProvisionTask $task): \App\Provisioning\Model\ProvisionResult
    {
        return new \App\Provisioning\Model\ProvisionResult();
    }
}
