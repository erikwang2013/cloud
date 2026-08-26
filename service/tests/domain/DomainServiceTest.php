<?php

namespace Tests\domain;

use App\domain\model\DnsRecord;
use App\domain\model\DnsZone;
use App\domain\model\DomainTld;
use App\domain\service\DomainService;
use App\provisioning\model\ProvisionTask;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class DomainServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        if (!Model::getEventDispatcher()) {
            Model::setEventDispatcher(
                new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container)
            );
        }

        $schema = $capsule->schema();
        $schema->create('domain_tlds', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->string('tld');
            $t->string('registrar');
            $t->decimal('retail_price', 14, 4);
            $t->decimal('promo_price', 14, 4)->nullable();
            $t->timestamp('promo_end_at')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });
        $schema->create('dns_zones', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('user_id');
            $t->string('domain_name');
            $t->timestamps();
        });
        $schema->create('dns_records', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('zone_id');
            $t->string('type');
            $t->string('name');
            $t->text('value');
            $t->integer('ttl')->default(600);
            $t->integer('priority')->nullable();
            $t->timestamps();
        });
        $schema->create('provision_tasks', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('order_id')->default(0);
            $t->unsignedBigInteger('order_item_id')->default(0);
            $t->string('product_type');
            $t->string('provider');
            $t->string('action');
            $t->string('status');
            $t->text('params')->nullable();
            $t->timestamp('next_retry_at')->nullable();
            $t->timestamps();
        });
    }

    private function seedTld(string $tld = '.com', array $overrides = []): DomainTld
    {
        $t = new DomainTld();
        $t->forceFill(array_merge([
            'tld'          => $tld,
            'registrar'    => 'verisign',
            'retail_price' => 10,
            'promo_price'  => 8,
            'status'       => 'active',
        ], $overrides));
        $t->save();
        return $t->refresh();
    }

    private function seedZone(int $userId, string $domain): DnsZone
    {
        $z = new DnsZone();
        $z->forceFill(['user_id' => $userId, 'domain_name' => $domain]);
        $z->save();
        return $z->refresh();
    }

    public function testCheckAvailabilityReturnsTldPricing(): void
    {
        $this->seedTld();

        $result = (new DomainService())->checkAvailability('example', '.com');

        $this->assertSame('example', $result['domain']);
        $this->assertSame('.com', $result['tld']);
        $this->assertTrue($result['available']);
        $this->assertSame(10, $result['price']['register']);
        $this->assertSame(10, $result['price']['renew']);
        $this->assertSame(10, $result['price']['transfer']);
        $this->assertSame(8, $result['promo_price']);
    }

    public function testCheckAvailabilityUnknownTldFails(): void
    {
        $this->expectException(ModelNotFoundException::class);
        (new DomainService())->checkAvailability('example', '.xyz');
    }

    public function testRegisterCreatesPendingProvisionTask(): void
    {
        $this->seedTld();

        (new DomainService())->register(1, 'example', '.com', [
            'order_id' => 77,
            'years'    => 2,
        ]);

        $task = ProvisionTask::first();
        $this->assertNotNull($task);
        $this->assertSame('domain', $task->product_type);
        $this->assertSame('verisign', $task->provider);
        $this->assertSame('register', $task->action);
        $this->assertSame('pending', $task->status);
        $this->assertSame(77, $task->order_id);

        $params = json_decode($task->params, true);
        // tld 配置表存带点 '.com'，拼接时 ltrim 前导点，避免 'example..com'
        $this->assertSame('example.com', $params['domain']);
        $this->assertSame(2, $params['years']);
        $this->assertTrue($params['whois_privacy']);
        $this->assertSame([], $params['nameservers']);
    }

    public function testRegisterUnknownTldFails(): void
    {
        $this->expectException(ModelNotFoundException::class);
        (new DomainService())->register(1, 'example', '.io');
    }

    public function testAddDnsRecordAppliesDefaults(): void
    {
        $this->seedZone(1, 'example.com');

        $record = (new DomainService())->addDnsRecord(1, 'example.com', [
            'type'  => 'A',
            'name'  => '@',
            'value' => '1.2.3.4',
        ]);

        $this->assertNotNull($record->id);
        $this->assertSame(600, $record->ttl);
        $this->assertNull($record->priority);
        $this->assertSame('A', $record->type);
    }

    public function testAddDnsRecordWithExplicitTtlAndPriority(): void
    {
        $this->seedZone(1, 'example.com');

        $record = (new DomainService())->addDnsRecord(1, 'example.com', [
            'type'     => 'MX',
            'name'     => 'mail',
            'value'    => 'mx.example.com',
            'ttl'      => 300,
            'priority' => 10,
        ]);

        $this->assertSame(300, $record->ttl);
        $this->assertSame(10, $record->priority);
    }

    public function testAddDnsRecordWithoutZoneFails(): void
    {
        $this->expectException(ModelNotFoundException::class);
        (new DomainService())->addDnsRecord(1, 'no-such.com', ['type' => 'A', 'name' => '@', 'value' => '1.1.1.1']);
    }

    private function seedRecord(DnsZone $zone, string $type, string $name, string $value): DnsRecord
    {
        $r = new DnsRecord();
        $r->forceFill(['zone_id' => $zone->id, 'type' => $type, 'name' => $name, 'value' => $value]);
        $r->save();
        return $r->refresh();
    }

    public function testListDnsRecordsScopedToUsersZone(): void
    {
        $zoneA = $this->seedZone(1, 'a.com');
        $zoneB = $this->seedZone(2, 'b.com');
        $this->seedRecord($zoneA, 'A', '@', '1.1.1.1');
        $this->seedRecord($zoneA, 'AAAA', '@', '::1');
        $this->seedRecord($zoneB, 'A', '@', '2.2.2.2');

        $records = (new DomainService())->listDnsRecords(1, 'a.com');

        $this->assertCount(2, $records);
        $this->assertSame('::1', $records[1]['value']);
    }

    public function testDeleteDnsRecordOnlyRemovesFromOwnZone(): void
    {
        $zoneA = $this->seedZone(1, 'a.com');
        $zoneB = $this->seedZone(2, 'b.com');
        $own = $this->seedRecord($zoneA, 'A', '@', '1.1.1.1');
        $other = $this->seedRecord($zoneB, 'A', '@', '2.2.2.2');

        // 跨 zone 删除尝试：zone 查得到但记录不属于该 zone → 静默删 0 行，记录保留
        $service = new DomainService();
        $service->deleteDnsRecord(2, 'b.com', $own->id);
        $this->assertSame(2, DnsRecord::count());

        // 正常删除：仅删目标记录
        $service->deleteDnsRecord(1, 'a.com', $own->id);
        $this->assertSame(1, DnsRecord::count());
        $this->assertNotNull(DnsRecord::find($other->id));
    }

    public function testGetTldsReturnsOnlyActive(): void
    {
        $this->seedTld('.com');
        $this->seedTld('.net', ['status' => 'inactive']);

        $tlds = (new DomainService())->getTlds();

        $this->assertCount(1, $tlds);
        $this->assertSame('.com', $tlds[0]['tld']);
    }

    public function testDomainModelsFillableAndRelations(): void
    {
        $this->assertContains('tld', (new DomainTld())->getFillable());
        $this->assertContains('domain_name', (new DnsZone())->getFillable());
        $this->assertContains('priority', (new DnsRecord())->getFillable());

        $this->assertTrue((new DnsZone())->records() instanceof \Illuminate\Database\Eloquent\Relations\HasMany);
        $this->assertTrue((new DnsRecord())->zone() instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo);
    }
}
