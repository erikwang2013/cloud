<?php

namespace Tests\cdn;

use App\cdn\provider\CdnProvider;
use App\provisioning\model\Resource;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class CdnProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'cloud_']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->schema()->create('resource_cdn', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->bigInteger('resource_id');
            $t->string('cdn_domain');
            $t->string('provider_type')->default('cloudflare');
            $t->bigInteger('provider_account_id')->nullable();
            $t->string('status')->default('active');
            $t->timestamp('purged_at')->nullable();
            $t->timestamps();
        });
        $capsule->schema()->create('provider_apis', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->string('code');
            $t->string('status');
            $t->string('name');
            $t->timestamps();
        });
    }

    public function testPurgeWithoutSnapshotDoesNotFallbackToCodeAccount(): void
    {
        Capsule::table('resource_cdn')->insert([
            'id' => 1, 'resource_id' => 1, 'cdn_domain' => 'a.example.com',
            'provider_type' => 'cloudflare', 'provider_account_id' => null,
        ]);
        // 存在 code=cdn-cloudflare 活动账号，strict 快照缺失时也不得静默换账号
        Capsule::table('provider_apis')->insert([
            'id' => 10, 'code' => 'cdn-cloudflare', 'status' => 'active', 'name' => 'cf',
        ]);

        $resource      = new Resource;
        $resource->id  = 1;
        $result        = (new CdnProvider)->purgeCache($resource, ['https://a.example.com/x']);

        $this->assertSame(0, $result['purged']);
        $this->assertNotEmpty($result['error']);
        $this->assertArrayNotHasKey('urls', $result);
    }

    public function testPurgeDisabledSnapshotAccountReturnsError(): void
    {
        Capsule::table('resource_cdn')->insert([
            'id' => 2, 'resource_id' => 2, 'cdn_domain' => 'b.example.com',
            'provider_type' => 'tencent', 'provider_account_id' => 20,
        ]);
        Capsule::table('provider_apis')->insert([
            'id' => 20, 'code' => 'cdn-tencent', 'status' => 'disabled', 'name' => 'tc',
        ]);

        $resource      = new Resource;
        $resource->id  = 2;
        $result        = (new CdnProvider)->purgeCache($resource, ['https://b.example.com/x']);

        $this->assertSame(0, $result['purged']);
        $this->assertNotEmpty($result['error']);
    }
}
