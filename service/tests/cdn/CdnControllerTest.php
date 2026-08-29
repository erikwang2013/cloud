<?php

namespace Tests\cdn;

use App\cdn\controller\CdnController;
use App\cdn\model\ResourceCdn;
use App\provisioning\model\Resource;
use Common\hashid\HashidService;
use Erikwang2013\Hashids\HashidsFactory;
use Erikwang2013\Hashids\HashidsManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class CdnControllerTest extends TestCase
{
    private const ENV_KEYS = [
        'CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ZONE_ID',
        'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY',
        'ALIYUN_CDN_ACCESS_KEY_ID', 'ALIYUN_CDN_ACCESS_KEY_SECRET',
        'TENCENT_CDN_SECRET_ID', 'TENCENT_CDN_SECRET_KEY',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Encryptable cast 需要 ENCRYPTION_KEY（与 UserModelTest 相同的隔离模式）
        $key = base64_decode('dW5pdC10ZXN0LW1hc3Rlci1rZXktMzJieXRlcy1hYmM=');
        foreach (['_ENV', '_SERVER'] as $super) {
            $GLOBALS[$super]['ENCRYPTION_KEY'] = $key;
            $GLOBALS[$super]['ENCRYPTION_CIPHER'] = 'aes-256-gcm';
        }
        putenv('ENCRYPTION_KEY=' . $key);
        putenv('ENCRYPTION_CIPHER=aes-256-gcm');
        \Erikwang2013\Encryptable\Encryption::setFallbackConfig(null);

        $capsule = new Capsule();
        // 与 config/database.php 一致：逻辑表名 + cloud_ 前缀 → 物理表 cloud_resources/cloud_resource_cdn
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'cloud_']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('resources', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('order_item_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('product_id');
            $t->string('type');
            $t->string('provider');
            $t->unsignedBigInteger('region_id');
            $t->string('status')->default('provisioning');
            $t->json('specs')->nullable();
            $t->dateTime('provisioned_at')->nullable();
            $t->dateTime('expired_at')->nullable();
            $t->timestamps();
        });
        $schema->create('provider_apis', function (Blueprint $t) {
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
            $t->bigInteger('provider_account_id')->nullable();
            $t->string('provider_domain_id')->nullable();
            $t->string('zone_id')->nullable();
            $t->json('cert_config')->nullable();
            $t->json('config')->nullable();
            $t->string('status')->default('pending');
            $t->dateTime('purged_at')->nullable();
            $t->timestamps();
        });

        // Response::success 会走 HashidService::encodeIds，注入 manager 避免读 config
        $config = [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'salt' => getenv('HASHIDS_SALT') ?: 'test-salt',
                    'length' => (int) (getenv('HASHIDS_LENGTH') ?: 12),
                    'alphabet' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
                ],
            ],
        ];
        $ref = new \ReflectionClass(HashidService::class);
        $prop = $ref->getProperty('manager');
        $prop->setValue(null, new HashidsManager($config, new HashidsFactory()));

        $this->clearCredentials();
    }

    protected function tearDown(): void
    {
        $this->clearCredentials();
        parent::tearDown();
    }

    private function clearCredentials(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
        }
    }

    private function makeRequest(array $data = [], int $userId = 1)
    {
        return new class($data, $userId) {
            public int $userId;
            private array $data;

            public function __construct(array $data, int $userId)
            {
                $this->data = $data;
                $this->userId = $userId;
            }

            public function input(string $name, $default = null)
            {
                return $this->data[$name] ?? $default;
            }
        };
    }

    private function decode($response): array
    {
        $body = is_object($response) && method_exists($response, 'rawBody') ? $response->rawBody() : (string) $response;
        return json_decode($body, true);
    }

    private function createResource(int $id, int $userId): void
    {
        $r = new Resource();
        $r->id = $id;
        $r->order_item_id = 1;
        $r->user_id = $userId;
        $r->product_id = 1;
        $r->type = 'cdn';
        $r->provider = 'cdn';
        $r->region_id = 1;
        $r->status = 'active';
        $r->save();
    }

    private function createProviderApi(int $id, string $code, string $apiKey = 'token', array $config = [], string $status = 'active'): void
    {
        $api = new \App\provisioning\model\ProviderApi();
        $api->id = $id;
        $api->name = $code;
        $api->code = $code;
        $api->api_key_encrypted = $apiKey; // Encryptable cast：明文入库自动加密
        $api->api_secret_encrypted = 'secret';
        $api->config = $config;
        $api->status = $status;
        $api->save();
    }

    private function createCdn(int $id, int $resourceId, string $domain, string $providerType = 'cloudflare', string $status = 'active'): void
    {
        $cdn = new ResourceCdn();
        $cdn->id = $id;
        $cdn->resource_id = $resourceId;
        $cdn->cdn_domain = $domain;
        $cdn->origin_type = 'server';
        $cdn->origin_value = 'origin.example.com';
        $cdn->provider_type = $providerType;
        $cdn->status = $status;
        $cdn->save();
    }

    // ---- create：参数校验（4001） ----

    public function testCreateMissingParamsReturns4001(): void
    {
        $this->createResource(1, 1);
        $body = $this->decode((new CdnController())->create($this->makeRequest([
            'origin_value' => 'origin.example.com',
            'resource_id' => 1,
        ])));
        $this->assertSame(4001, $body['code']);
    }

    public function testCreateInvalidDomainReturns4001(): void
    {
        $this->createResource(1, 1);
        $body = $this->decode((new CdnController())->create($this->makeRequest([
            'domain' => 'not_a_domain!',
            'origin_value' => 'origin.example.com',
            'resource_id' => 1,
        ])));
        $this->assertSame(4001, $body['code']);
    }

    public function testCreateInvalidProviderReturns4001(): void
    {
        $this->createResource(1, 1);
        $body = $this->decode((new CdnController())->create($this->makeRequest([
            'domain' => 'cdn.example.com',
            'origin_value' => 'origin.example.com',
            'resource_id' => 1,
            'provider_type' => 'fastly',
        ])));
        $this->assertSame(4001, $body['code']);
    }

    public function testCreateCertConfigNonArrayReturns4001(): void
    {
        $this->createResource(1, 1);
        $body = $this->decode((new CdnController())->create($this->makeRequest([
            'domain' => 'cdn.example.com',
            'origin_value' => 'origin.example.com',
            'resource_id' => 1,
            'cert_config' => 'not-an-array',
        ])));
        $this->assertSame(4001, $body['code']);
    }

    public function testCreateCertFieldTooLargeReturns4001(): void
    {
        $this->createResource(1, 1);
        $body = $this->decode((new CdnController())->create($this->makeRequest([
            'domain' => 'cdn.example.com',
            'origin_value' => 'origin.example.com',
            'resource_id' => 1,
            'cert_config' => ['cert' => str_repeat('x', 65537)],
        ])));
        $this->assertSame(4001, $body['code']);
    }

    // ---- create：权限隔离（404 不泄露存在性） ----

    public function testCreateNonexistentResourceReturns404(): void
    {
        $body = $this->decode((new CdnController())->create($this->makeRequest([
            'domain' => 'cdn.example.com',
            'origin_value' => 'origin.example.com',
            'resource_id' => 999,
        ])));
        $this->assertSame(404, $body['code']);
    }

    public function testCreateOtherUsersResourceReturns404(): void
    {
        $this->createResource(1, 2); // 属于 user 2
        $body = $this->decode((new CdnController())->create($this->makeRequest([
            'domain' => 'cdn.example.com',
            'origin_value' => 'origin.example.com',
            'resource_id' => 1,
        ], 1))); // user 1 访问
        $this->assertSame(404, $body['code']);
    }

    // ---- create：凭据未配置（4003） ----

    public function testCreateWithoutCredentialsReturns4003(): void
    {
        $this->createResource(1, 1);
        $body = $this->decode((new CdnController())->create($this->makeRequest([
            'domain' => 'cdn.example.com',
            'origin_value' => 'origin.example.com',
            'resource_id' => 1,
            'provider_type' => 'aliyun',
        ])));
        $this->assertSame(4003, $body['code']);
    }

    // ---- create：幂等（同 resource+domain 已 active 直接返回） ----

    public function testCreateIdempotentWhenActiveDomainExists(): void
    {
        $this->createResource(1, 1);
        $this->createProviderApi(1, 'cdn-cloudflare', 'token', ['zone_id' => 'zone-1']);
        $this->createCdn(1, 1, 'cdn.example.com', 'cloudflare', 'active');

        $body = $this->decode((new CdnController())->create($this->makeRequest([
            'domain' => 'cdn.example.com',
            'origin_value' => 'origin.example.com',
            'resource_id' => 1,
            'provider_type' => 'cloudflare',
        ])));

        $this->assertSame(0, $body['code']);
        $this->assertSame(1, ResourceCdn::count()); // 未新建重复记录
        $this->assertSame('active', ResourceCdn::first()->status);
    }

    public function testCreateIdempotentIcpHintMatchesExistingDomainsProvider(): void
    {
        $this->createResource(1, 1);
        $this->createProviderApi(1, 'cdn-aliyun', 'key', [], 'disabled');
        $this->createProviderApi(2, 'cdn-aliyun', 'key', [], 'active');
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'active');
        ResourceCdn::where('id', 1)->update(['provider_account_id' => 1]); // 快照禁用 → 降级 code 活动账号

        // 重复 create 传不同 provider（cloudflare），ICP 提示应与已有域 provider（aliyun，需 ICP）一致
        $body = $this->decode((new CdnController())->create($this->makeRequest([
            'domain' => 'cdn.example.com',
            'origin_value' => 'origin.example.com',
            'resource_id' => 1,
            'provider_type' => 'cloudflare',
        ])));

        $this->assertSame(0, $body['code']);
        $this->assertTrue($body['data']['requires_icp_registration']);
    }

    public function testCreateIdempotentBranchWithoutCredentialsReturns4003(): void
    {
        $this->createResource(1, 1);
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'active'); // 无快照 account_id

        // 幂等分支：无快照、无 code 账号、无 env → 4003 而非 500
        $body = $this->decode((new CdnController())->create($this->makeRequest([
            'domain' => 'cdn.example.com',
            'origin_value' => 'origin.example.com',
            'resource_id' => 1,
            'provider_type' => 'aliyun',
        ])));

        $this->assertSame(4003, $body['code']);
    }

    // ---- 行为契约：create 降级 + purge 严格快照 ----

    public function testCreateDegradesToActiveAccountWhenSnapshotDisabled(): void
    {
        $this->createResource(1, 1);
        $this->createProviderApi(1, 'cdn-cloudflare', 'token', ['zone_id' => 'zone-1'], 'disabled');
        $this->createProviderApi(2, 'cdn-cloudflare', 'token', ['zone_id' => 'zone-2'], 'active');
        $this->createCdn(1, 1, 'cdn.example.com', 'cloudflare', 'active');
        ResourceCdn::where('id', 1)->update(['provider_account_id' => 1]); // 快照指向禁用账号

        // 幂等分支：非 strict 降级到同 code 活动账号，不报错
        $body = $this->decode((new CdnController())->create($this->makeRequest([
            'domain' => 'cdn.example.com',
            'origin_value' => 'origin.example.com',
            'resource_id' => 1,
            'provider_type' => 'cloudflare',
        ])));

        $this->assertSame(0, $body['code']);
    }

    public function testPurgeSnapshotDisabledDoesNotFallback(): void
    {
        $this->createResource(1, 1);
        $this->createProviderApi(1, 'cdn-aliyun', 'key', [], 'disabled');
        $this->createProviderApi(2, 'cdn-aliyun', 'key', [], 'active'); // 存在活动账号，也不得静默切换
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'active');
        ResourceCdn::where('id', 1)->update(['provider_account_id' => 1]); // 快照账号已禁用

        $body = $this->decode((new CdnController())->purgeCache($this->makeRequest([
            'urls' => ['https://cdn.example.com/a'],
        ]), 1));

        $this->assertSame(4003, $body['code']); // 严格快照：缺失/禁用明确报错，不静默换账号
    }

    public function testPurgeWithoutSnapshotAccountReturns4003(): void
    {
        $this->createResource(1, 1);
        $this->createProviderApi(1, 'cdn-aliyun', 'key', [], 'active'); // 同 code 活动账号存在
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'active'); // 但无快照 account_id

        $body = $this->decode((new CdnController())->purgeCache($this->makeRequest([
            'urls' => ['https://cdn.example.com/a'],
        ]), 1));

        $this->assertSame(4003, $body['code']);
    }

    // ---- destroy：权限隔离 + 幂等 ----

    public function testDestroyOtherUsersDomainThrowsNotFound(): void
    {
        $this->createResource(2, 2);
        $this->createCdn(2, 2, 'cdn.other.com', 'aliyun', 'active');

        $this->expectException(ModelNotFoundException::class);
        (new CdnController())->destroy($this->makeRequest([], 1), 2);
    }

    public function testDestroyAlreadyDeletedIsIdempotent(): void
    {
        $this->createResource(1, 1);
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'deleted');

        // 无凭据也不报错：status=deleted 时不再调用 provider API
        $body = $this->decode((new CdnController())->destroy($this->makeRequest([], 1), 1));
        $this->assertSame(0, $body['code']);
        $this->assertSame('deleted', ResourceCdn::find(1)->status);
    }

    public function testDestroyWithoutSnapshotDoesNotFallbackToCodeAccount(): void
    {
        $this->createResource(1, 1);
        $this->createProviderApi(1, 'cdn-aliyun', 'key', [], 'active'); // 同 code 活动账号存在
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'active'); // 但无快照 account_id

        $body = $this->decode((new CdnController())->destroy($this->makeRequest([], 1), 1));

        $this->assertSame(4003, $body['code']);
    }

    public function testDestroyActiveWithoutCredentialsReturns4003(): void
    {
        $this->createResource(1, 1);
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'active');

        $body = $this->decode((new CdnController())->destroy($this->makeRequest([], 1), 1));
        $this->assertSame(4003, $body['code']);
    }

    // ---- purge：参数校验（4001） ----

    public function testPurgeMissingUrlsReturns4001(): void
    {
        $this->createResource(1, 1);
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'active');

        $body = $this->decode((new CdnController())->purgeCache($this->makeRequest([]), 1));
        $this->assertSame(4001, $body['code']);
    }

    public function testPurgeNonHttpUrlReturns4001(): void
    {
        $this->createResource(1, 1);
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'active');

        $body = $this->decode((new CdnController())->purgeCache($this->makeRequest([
            'urls' => ['ftp://cdn.example.com/a'],
        ]), 1));
        $this->assertSame(4001, $body['code']);
    }

    public function testPurgeTooManyUrlsReturns4001(): void
    {
        $this->createResource(1, 1);
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'active');

        $urls = array_map(fn (int $i) => "https://cdn.example.com/f{$i}", range(1, 101));
        $body = $this->decode((new CdnController())->purgeCache($this->makeRequest(['urls' => $urls]), 1));
        $this->assertSame(4001, $body['code']);
    }

    // ---- purge：权限隔离 ----

    public function testPurgeOtherUsersDomainThrowsNotFound(): void
    {
        $this->createResource(2, 2);
        $this->createCdn(2, 2, 'cdn.other.com', 'aliyun', 'active');

        $this->expectException(ModelNotFoundException::class);
        (new CdnController())->purgeCache($this->makeRequest(['urls' => ['https://cdn.other.com/a']], 1), 2);
    }

    // ---- purge：重复 URL 去重后通过校验（幂等提交不报参数错误） ----

    public function testPurgeDuplicateUrlsPassValidationAfterDedup(): void
    {
        $this->createResource(1, 1);
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'active');

        // 同 URL 集合重复提交：去重后 1 条，参数校验通过；无凭据走到 provider 层报 4003（而非 4001）
        $body = $this->decode((new CdnController())->purgeCache($this->makeRequest([
            'urls' => ['https://cdn.example.com/a', 'https://cdn.example.com/a', 'https://cdn.example.com/a'],
        ]), 1));
        $this->assertSame(4003, $body['code']);
    }

    public function testPurgeDuplicateUrlsWithWhitespaceDedup(): void
    {
        $this->createResource(1, 1);
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'active');

        // trim + 去重后通过校验（不报 4001 参数错误）
        $body = $this->decode((new CdnController())->purgeCache($this->makeRequest([
            'urls' => [' https://cdn.example.com/a ', 'https://cdn.example.com/a'],
        ]), 1));
        $this->assertSame(4003, $body['code']);
    }

    // ---- show：权限隔离 ----

    public function testShowOtherUsersDomainThrowsNotFound(): void
    {
        $this->createResource(2, 2);
        $this->createCdn(2, 2, 'cdn.other.com', 'aliyun', 'active');

        $this->expectException(ModelNotFoundException::class);
        (new CdnController())->show($this->makeRequest([], 1), 2);
    }

    public function testShowOwnDomainReturnsCertFreeData(): void
    {
        $this->createResource(1, 1);
        $this->createCdn(1, 1, 'cdn.example.com', 'aliyun', 'active');
        ResourceCdn::where('id', 1)->update(['cert_config' => json_encode(['cert' => 'CERT', 'key' => 'KEY'])]);

        $body = $this->decode((new CdnController())->show($this->makeRequest([], 1), 1));
        $this->assertSame(0, $body['code']);
        $this->assertArrayNotHasKey('cert_config', $body['data']);
        $this->assertArrayNotHasKey('key', $body['data']);
    }
}
