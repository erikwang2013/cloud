<?php

namespace Tests\provisioning;

use App\provisioning\model\ProviderApi;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class ProviderApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $key = base64_decode('dW5pdC10ZXN0LW1hc3Rlci1rZXktMzJieXRlcy1hYmM=');
        foreach (['_ENV', '_SERVER'] as $super) {
            $GLOBALS[$super]['ENCRYPTION_KEY'] = $key;
            $GLOBALS[$super]['ENCRYPTION_CIPHER'] = 'aes-256-gcm';
        }
        putenv('ENCRYPTION_KEY=' . $key);
        putenv('ENCRYPTION_CIPHER=aes-256-gcm');
        \Erikwang2013\Encryptable\Encryption::setFallbackConfig(null);

        $capsule = new Capsule();
        // 与 config/database.php 一致：逻辑表名 + cloud_ 前缀 → 物理表 cloud_provider_apis
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'cloud_']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->schema()->create('provider_apis', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->string('name')->nullable();
            $t->string('code');
            $t->text('api_key_encrypted')->nullable();
            $t->text('api_secret_encrypted')->nullable();
            $t->text('webhook_secret')->nullable();
            $t->json('config')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });
    }

    public function testCredentialFieldsNeverSerialized(): void
    {
        $hidden = (new ProviderApi())->getHidden();
        $this->assertContains('api_key_encrypted', $hidden);
        $this->assertContains('api_secret_encrypted', $hidden);
        $this->assertContains('webhook_secret', $hidden);
    }

    public function testCredentialRoundTripEncryptedAtRest(): void
    {
        $api = new ProviderApi();
        $api->name = 'aliyun-prod';
        $api->code = 'cdn-aliyun';
        $api->api_key_encrypted = 'LTAI5tPLAINKEY';
        $api->api_secret_encrypted = 'plain-secret-123';
        $api->webhook_secret = 'wh-secret';
        $api->config = ['region' => 'cn-hangzhou'];
        $api->status = 'active';
        $api->save();

        // 模型读取：自动解密，与明文一致
        $found = ProviderApi::find($api->id);
        $this->assertSame('LTAI5tPLAINKEY', $found->api_key_encrypted);
        $this->assertSame('plain-secret-123', $found->api_secret_encrypted);
        $this->assertSame('wh-secret', $found->webhook_secret);
        $this->assertSame(['region' => 'cn-hangzhou'], $found->config);

        // 库中原样存密文，明文绝不落库（裸 SQL 用物理表名，前缀不自动注入）
        $raw = Capsule::connection()->selectOne('select * from cloud_provider_apis where id = ?', [$api->id]);
        $this->assertStringNotContainsString('LTAI5tPLAINKEY', (string) $raw->api_key_encrypted);
        $this->assertStringNotContainsString('plain-secret-123', (string) $raw->api_secret_encrypted);
        $this->assertTrue(\Erikwang2013\Encryptable\Encryption::isEncrypted($raw->api_key_encrypted));
    }

    public function testCredentialFieldsAbsentFromSerializedArray(): void
    {
        $api = new ProviderApi();
        $api->name = 'tencent-prod';
        $api->code = 'cdn-tencent';
        $api->api_key_encrypted = 'AKIDsecret';
        $api->api_secret_encrypted = 's-key';
        $api->webhook_secret = 'wh';
        $api->save();

        $array = $api->toArray();
        $this->assertArrayNotHasKey('api_key_encrypted', $array);
        $this->assertArrayNotHasKey('api_secret_encrypted', $array);
        $this->assertArrayNotHasKey('webhook_secret', $array);
    }
}
