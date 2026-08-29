<?php

namespace Tests\cdn;

use App\cdn\model\ResourceCdn;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class ResourceCdnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        // 与 config/database.php 一致：逻辑表名 + cloud_ 前缀 → 物理表 cloud_resource_cdn
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'cloud_']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('resource_cdn', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('resource_id');
            $t->string('cdn_domain');
            $t->string('origin_type')->default('server');
            $t->string('origin_value');
            $t->string('plan')->default('standard');
            $t->boolean('ssl')->default(true);
            $t->json('cache_rules')->nullable();
            $t->string('provider_type')->default('cloudflare');
            $t->string('provider_domain_id')->nullable();
            $t->string('zone_id')->nullable();
            $t->json('cert_config')->nullable();
            $t->json('config')->nullable();
            $t->string('status')->default('pending');
            $t->dateTime('purged_at')->nullable();
            $t->timestamps();
        });
    }

    public function testTableNameWithPrefixResolvesToCloudResourceCdn(): void
    {
        // 逻辑表名 resource_cdn + config/database.php 的 cloud_ 前缀 → 物理表 cloud_resource_cdn
        $this->assertSame('resource_cdn', (new ResourceCdn())->getTable());
        $this->assertSame('cloud_', Capsule::connection()->getTablePrefix());
        $this->assertSame('cloud_resource_cdn', Capsule::connection()->getTablePrefix() . (new ResourceCdn())->getTable());
    }

    public function testFillableFields(): void
    {
        $fillable = (new ResourceCdn())->getFillable();
        foreach (['resource_id', 'cdn_domain', 'origin_type', 'origin_value', 'plan', 'ssl', 'cache_rules', 'status', 'purged_at', 'provider_type', 'provider_domain_id', 'zone_id', 'cert_config', 'config'] as $field) {
            $this->assertContains($field, $fillable);
        }
    }

    public function testCasts(): void
    {
        $cdn = new ResourceCdn();
        $cdn->ssl = true;
        $cdn->cache_rules = ['/*' => 86400];

        $this->assertTrue($cdn->ssl);
        $this->assertIsBool($cdn->ssl);
        $this->assertIsArray($cdn->cache_rules);
        $this->assertSame(86400, $cdn->cache_rules['/*']);
    }

    public function testResourceRelation(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new ResourceCdn())->resource()
        );
    }

    public function testCrudRoundTripWithProviderFields(): void
    {
        $cdn = new ResourceCdn();
        $cdn->resource_id = 1;
        $cdn->cdn_domain = 'cdn.example.com';
        $cdn->origin_type = 'server';
        $cdn->origin_value = 'origin.example.com';
        $cdn->provider_type = 'aliyun';
        $cdn->provider_domain_id = 'aliyun-123';
        $cdn->zone_id = 'zone-9';
        $cdn->cert_config = ['name' => 'wild', 'cert' => 'CERT', 'key' => 'KEY'];
        $cdn->save();

        $found = ResourceCdn::find($cdn->id);
        $this->assertSame('cdn.example.com', $found->cdn_domain);
        $this->assertSame('aliyun', $found->provider_type);
        $this->assertSame('aliyun-123', $found->provider_domain_id);
        $this->assertSame('zone-9', $found->zone_id);
        $this->assertSame('KEY', $found->cert_config['key']);
        $this->assertSame('pending', $found->status);

        $found->update(['status' => 'deleted']);
        $this->assertSame('deleted', ResourceCdn::find($cdn->id)->status);
    }

    public function testCertConfigHiddenFromSerializedArray(): void
    {
        $cdn = new ResourceCdn();
        $cdn->cert_config = ['cert' => 'CERT', 'key' => 'KEY'];
        $cdn->cdn_domain = 'cdn.example.com';

        $array = $cdn->toArray();
        $this->assertArrayNotHasKey('cert_config', $array);
        $this->assertArrayNotHasKey('key', $array);
    }

    public function testFindByResourceAndDomain(): void
    {
        $cdn = new ResourceCdn();
        $cdn->resource_id = 7;
        $cdn->cdn_domain = 'cdn.example.com';
        $cdn->origin_type = 'server';
        $cdn->origin_value = 'origin.example.com';
        $cdn->provider_type = 'tencent';
        $cdn->status = 'active';
        $cdn->save();

        $hit = ResourceCdn::where('resource_id', 7)->where('cdn_domain', 'cdn.example.com')->first();
        $this->assertNotNull($hit);
        $this->assertSame('tencent', $hit->provider_type);
        $this->assertSame('active', $hit->status);
    }
}
