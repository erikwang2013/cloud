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
use App\provisioning\provider\kvm\KvmProvider;
use App\provisioning\provider\kvm\SimulatedKvmDriver;
use Common\snowflake\SnowflakeService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * KVM provider 全流程（模拟驱动 + 内存 sqlite + 本机 Redis 锁）：
 * create 落库三条隔离服务记录 + IP/Disk，destroy 释放。
 */
final class KvmProviderTest extends TestCase
{
    private SimulatedKvmDriver $driver;
    private KvmProvider $provider;
    private const REGION = 999001;

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
        // 目录连跑时模型可能已在无 dispatcher 下 boot（creating 钩子丢失），补挂雪花主键
        foreach ([
            HostMachine::class, Resource::class, Disk::class, IpPool::class,
            IpAllocation::class, NetworkService::class, FirewallService::class,
            SwitchService::class, ProvisionTask::class,
        ] as $model) {
            $model::creating(function ($m) {
                if (empty($m->getKey())) {
                    $m->setAttribute($m->getKeyName(), SnowflakeService::nextId());
                }
            });
        }

        $this->createSchema($capsule->schema());
        $this->bootstrapRedis();
        $this->driver   = new SimulatedKvmDriver();
        $this->provider = new KvmProvider($this->driver);
    }

    protected function tearDown(): void
    {
        \support\Redis::del("lock:provision:region:" . self::REGION . ":kvm");
        parent::tearDown();
    }

    public function testCreateFullFlowWithIsolation(): void
    {
        $host = $this->makeHost();
        $this->makePool($host->id);
        $resource = $this->makeResource();

        $result = $this->provider->create($this->taskFor($resource));

        $this->assertSame('success', $result->status, $result->errorMessage ?? 'no error');
        $this->assertSame('kvm-' . $resource->id, $result->data['vm_id']);
        $this->assertSame('10.0.0.10', $result->data['ip_address']);

        // 三条服务记录 + 磁盘落库且 active
        $net  = NetworkService::where('resource_id', $resource->id)->firstOrFail();
        $fw   = FirewallService::where('resource_id', $resource->id)->firstOrFail();
        $sw   = SwitchService::where('resource_id', $resource->id)->firstOrFail();
        $disk = Disk::where('resource_id', $resource->id)->firstOrFail();
        $this->assertSame('active', $net->status);
        $this->assertSame('active', $fw->status);
        $this->assertSame('active', $sw->status);
        $this->assertSame('active', $disk->status);
        $this->assertSame($net->id, $sw->network_service_id);

        // 每 VM 独立命名：bridge/table/veth 与 VM 一一对应（隔离单元）
        $this->assertSame("br-vm{$resource->id}", $net->bridge_name);
        $this->assertSame("fw-vm{$resource->id}", $fw->table_name);
        $this->assertSame("veth{$resource->id}a", $sw->veth_host);
        $this->assertSame("veth{$resource->id}b", $sw->veth_guest);
        $this->assertArrayHasKey("br-vm{$resource->id}", $this->driver->bridges);
        $this->assertArrayHasKey("fw-vm{$resource->id}", $this->driver->tables);
        $this->assertArrayHasKey("veth{$resource->id}a", $this->driver->veths);
        $this->assertSame('running', $this->driver->vms["kvm-{$resource->id}"]['status']);

        // IP 分配 + 宿主聚合重算
        $ip = IpAllocation::where('resource_id', $resource->id)->firstOrFail();
        $this->assertNull($ip->released_at);
        $host->refresh();
        $specs = $host->specs;
        $this->assertSame(2, $specs['cpu_allocated']);
        $this->assertSame(2, $specs['ram_allocated_gb']);
        $this->assertSame(20, $specs['disk_allocated_gb']);
    }

    public function testSecondVmGetsDistinctBridgeAndTable(): void
    {
        $host = $this->makeHost();
        $this->makePool($host->id);
        $r1 = $this->makeResource();
        $r2 = $this->makeResource();

        $r1r = $this->provider->create($this->taskFor($r1));
        $this->assertSame('success', $r1r->status, $r1r->errorMessage ?? 'no error');
        $r2r = $this->provider->create($this->taskFor($r2));
        $this->assertSame('success', $r2r->status, $r2r->errorMessage ?? 'no error');

        $bridges = NetworkService::where('host_machine_id', $host->id)->pluck('bridge_name')->all();
        $tables  = FirewallService::where('host_machine_id', $host->id)->pluck('table_name')->all();
        $this->assertCount(2, array_unique($bridges));
        $this->assertCount(2, array_unique($tables));
        $this->assertSame("br-vm{$r1->id}", $bridges[0]);
        $this->assertSame("br-vm{$r2->id}", $bridges[1]);
    }

    public function testCreateSerializedByRegionLock(): void
    {
        $host = $this->makeHost();
        $this->makePool($host->id);
        $resource = $this->makeResource();

        \support\Redis::set("lock:provision:region:" . self::REGION . ":kvm", 'other', 'EX', 300, 'NX');

        $result = $this->provider->create($this->taskFor($resource));

        $this->assertSame('retryable', $result->status);
        $this->assertSame(0, NetworkService::where('resource_id', $resource->id)->count());
        $this->assertSame(0, Disk::where('resource_id', $resource->id)->count());
    }

    public function testDestroyReleasesAllServices(): void
    {
        $host = $this->makeHost();
        $this->makePool($host->id);
        $resource = $this->makeResource();
        $this->provider->create($this->taskFor($resource));

        $result = $this->provider->destroy($resource);

        $this->assertSame('success', $result->status);
        $this->assertSame('destroyed', $this->driver->vms["kvm-{$resource->id}"]['status']);
        $this->assertSame(0, NetworkService::where('resource_id', $resource->id)->count());
        $this->assertSame(0, FirewallService::where('resource_id', $resource->id)->count());
        $this->assertSame(0, SwitchService::where('resource_id', $resource->id)->count());
        $this->assertSame('destroyed', Disk::where('resource_id', $resource->id)->first()->status);
        $this->assertNotNull(IpAllocation::where('resource_id', $resource->id)->first()->released_at);
    }

    public function testStatusMapsDriverState(): void
    {
        $host = $this->makeHost();
        $this->makePool($host->id);
        $resource = $this->makeResource();
        $this->provider->create($this->taskFor($resource));

        $this->assertSame('running', $this->provider->status($resource)->status);
    }

    private function makeHost(): HostMachine
    {
        return HostMachine::create([
            'region_id'    => self::REGION,
            'name'         => 'kvm-host-1',
            'ip_address'   => '192.168.1.10',
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
            'region_id' => self::REGION,
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
            'region_id'    => self::REGION,
            'params'       => json_encode(['specs' => ['cpu' => 2, 'ram' => 2, 'system_disk' => 20]]),
        ]);
    }

    private function bootstrapRedis(): void
    {
        $ref = new \ReflectionProperty(\support\Redis::class, 'manager');
        $ref->setAccessible(true);
        $cfg = require __DIR__ . '/../../config/redis.php';
        $ref->setValue(null, new \Illuminate\Redis\RedisManager('default', 'phpredis', $cfg));
        \support\Redis::del("lock:provision:region:" . self::REGION . ":kvm");
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
