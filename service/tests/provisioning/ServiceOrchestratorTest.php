<?php

namespace Tests\provisioning;

use App\provisioning\model\Disk;
use App\provisioning\model\FirewallService;
use App\provisioning\model\HostMachine;
use App\provisioning\model\IpAllocation;
use App\provisioning\model\IpPool;
use App\provisioning\model\NetworkService;
use App\provisioning\model\ProvisionTask;
use App\provisioning\model\Resource;
use App\provisioning\model\SwitchService;
use App\provisioning\provider\kvm\KvmDriverInterface;
use App\provisioning\provider\kvm\SimulatedKvmDriver;
use App\provisioning\service\ServiceOrchestrator;
use Common\snowflake\SnowflakeService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 编排层：多 VM 隔离命名 + 驱动失败回滚（无 Redis，纯 DB + 模拟驱动）。
 */
final class ServiceOrchestratorTest extends TestCase
{
    private ServiceOrchestrator $orchestrator;
    private SimulatedKvmDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        if (!\Illuminate\Database\Eloquent\Model::getEventDispatcher()) {
            \Illuminate\Database\Eloquent\Model::setEventDispatcher(
                new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container)
            );
        }
        foreach ([
            HostMachine::class, Resource::class, Disk::class, IpPool::class,
            IpAllocation::class, NetworkService::class, FirewallService::class,
            SwitchService::class,
        ] as $model) {
            $model::creating(function ($m) {
                if (empty($m->getKey())) {
                    $m->setAttribute($m->getKeyName(), SnowflakeService::nextId());
                }
            });
        }

        $this->createSchema($capsule->schema());
        $this->orchestrator = new ServiceOrchestrator();
        $this->driver       = new SimulatedKvmDriver();
    }

    public function testProvisionCreatesIsolatedPerVmServices(): void
    {
        $host = $this->makeHost();
        $pool = $this->makePool($host->id);
        $r1 = $this->makeResource();
        $r2 = $this->makeResource();

        $this->orchestrator->provision($host, $r1, $this->taskFor($r1), $this->driver, ['cpu' => 2, 'ram' => 2, 'system_disk' => 20]);
        $this->orchestrator->provision($host, $r2, $this->taskFor($r2), $this->driver, ['cpu' => 1, 'ram' => 1, 'system_disk' => 10]);

        // 两条 VM 的隔离单元命名互不重叠
        $bridges = NetworkService::where('host_machine_id', $host->id)->pluck('bridge_name')->all();
        $tables  = FirewallService::where('host_machine_id', $host->id)->pluck('table_name')->all();
        $veths   = SwitchService::where('host_machine_id', $host->id)->pluck('veth_host')->all();
        $this->assertSame(["br-vm{$r1->id}", "br-vm{$r2->id}"], $bridges);
        $this->assertSame(["fw-vm{$r1->id}", "fw-vm{$r2->id}"], $tables);
        $this->assertSame(["veth{$r1->id}a", "veth{$r2->id}a"], $veths);

        // 驱动状态与记录一致，两张 VM 各自 running
        $this->assertSame('running', $this->driver->status("kvm-{$r1->id}"));
        $this->assertSame('running', $this->driver->status("kvm-{$r2->id}"));

        // IP 顺序分配不重复
        $ips = IpAllocation::where('ip_pool_id', $pool->id)->pluck('ip_address')->all();
        $this->assertSame(['10.0.0.10', '10.0.0.11'], $ips);
        $this->assertCount(2, array_unique($ips));
    }

    public function testDriverFailureRollsBackRecords(): void
    {
        $host = $this->makeHost();
        $pool = $this->makePool($host->id);
        $resource = $this->makeResource();
        $failing = new class implements KvmDriverInterface {
            public function createVm(array $spec): string
            {
                throw new \RuntimeException('virsh down');
            }
            public function createBridge(string $bridgeName): void {}
            public function createVeth(string $hostName, string $guestName, string $bridgeName, string $macAddress): void {}
            public function attachDisk(string $vmId, string $devicePath, int $sizeGb): void {}
            public function startVm(string $vmId): void {}
            public function applyFirewall(string $tableName, string $defaultPolicy, array $rules): void {}
            public function destroyVm(string $vmId): void {}
            public function removeBridge(string $bridgeName): void {}
            public function removeVeth(string $hostName): void {}
            public function removeFirewall(string $tableName): void {}
            public function status(string $vmId): string { return 'error'; }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('virsh down');
        try {
            $this->orchestrator->provision($host, $resource, $this->taskFor($resource), $failing, ['cpu' => 2, 'ram' => 2, 'system_disk' => 20]);
        } finally {
            // 回滚：服务记录删除、磁盘标记 destroyed、IP 释放
            $this->assertSame(0, NetworkService::where('resource_id', $resource->id)->count());
            $this->assertSame(0, FirewallService::where('resource_id', $resource->id)->count());
            $this->assertSame(0, SwitchService::where('resource_id', $resource->id)->count());
            $this->assertSame('destroyed', Disk::where('resource_id', $resource->id)->first()->status);
            $this->assertNotNull(IpAllocation::where('resource_id', $resource->id)->first()->released_at);
        }
    }

    private function makeHost(): HostMachine
    {
        return HostMachine::create([
            'region_id'    => 999002,
            'name'         => 'kvm-host-2',
            'storage_pool' => 'default',
            'hypervisor'   => 'kvm',
            'status'       => 'online',
            'specs'        => [
                'cpu_total' => 8, 'cpu_allocated' => 0,
                'ram_total_gb' => 16, 'ram_allocated_gb' => 0,
                'disk_total_gb' => 200, 'disk_allocated_gb' => 0,
            ],
        ]);
    }

    private function makePool(int $hostId): IpPool
    {
        return IpPool::create([
            'host_machine_id' => $hostId,
            'ip_start'        => '10.0.0.10',
            'ip_end'          => '10.0.0.20',
            'gateway'         => '10.0.0.1',
            'total_count'     => 10,
            'used_count'      => 0,
        ]);
    }

    private function makeResource(): Resource
    {
        return Resource::create([
            'type'      => 'server',
            'provider'  => 'kvm',
            'region_id' => 999002,
            'status'    => 'pending',
            'specs'     => ['cpu' => 2, 'ram' => 2, 'system_disk' => 20],
        ]);
    }

    private function taskFor(Resource $resource): ProvisionTask
    {
        return new ProvisionTask([
            'resource_id'  => $resource->id,
            'product_type' => 'server',
            'provider'     => 'kvm',
            'region_id'    => 999002,
            'params'       => json_encode(['specs' => ['cpu' => 2, 'ram' => 2, 'system_disk' => 20]]),
        ]);
    }

    private function createSchema($schema): void
    {
        $schema->create('host_machines', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->integer('region_id');
            $t->string('name');
            $t->string('ip_address')->nullable();
            $t->string('proxmox_node')->nullable();
            $t->string('storage_pool')->nullable();
            $t->string('api_token_encrypted')->nullable();
            $t->text('specs');
            $t->string('status');
            $t->string('hypervisor')->default('proxmox');
            $t->string('kvm_connection')->nullable();
            $t->timestamps();
        });
        $schema->create('resources', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_item_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('type');
            $t->string('provider');
            $t->integer('region_id');
            $t->string('status');
            $t->text('specs');
            $t->dateTime('provisioned_at')->nullable();
            $t->dateTime('expired_at')->nullable();
            $t->timestamps();
        });
        $schema->create('disks', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('resource_id');
            $t->unsignedBigInteger('host_machine_id');
            $t->string('vm_id')->nullable();
            $t->integer('size_gb');
            $t->string('disk_type');
            $t->string('storage_pool')->nullable();
            $t->string('device_path')->nullable();
            $t->string('status');
            $t->timestamps();
        });
        $schema->create('ip_pools', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('host_machine_id');
            $t->string('ip_start');
            $t->string('ip_end');
            $t->string('gateway');
            $t->integer('total_count');
            $t->integer('used_count')->default(0);
            $t->timestamps();
        });
        $schema->create('ip_allocations', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('ip_pool_id');
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->string('ip_address');
            $t->string('type');
            $t->dateTime('allocated_at');
            $t->dateTime('released_at')->nullable();
            $t->timestamps();
        });
        $schema->create('network_services', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('host_machine_id');
            $t->unsignedBigInteger('resource_id');
            $t->string('vm_id');
            $t->string('bridge_name');
            $t->string('subnet')->nullable();
            $t->string('gateway_ip')->nullable();
            $t->string('status');
            $t->timestamps();
        });
        $schema->create('firewall_services', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('host_machine_id');
            $t->unsignedBigInteger('resource_id');
            $t->string('vm_id');
            $t->string('table_name');
            $t->string('default_policy')->default('drop');
            $t->text('rules');
            $t->string('status');
            $t->timestamps();
        });
        $schema->create('switch_services', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('host_machine_id');
            $t->unsignedBigInteger('resource_id');
            $t->string('vm_id');
            $t->string('veth_host');
            $t->string('veth_guest');
            $t->string('mac_address');
            $t->unsignedBigInteger('network_service_id');
            $t->string('status');
            $t->timestamps();
        });
    }
}
