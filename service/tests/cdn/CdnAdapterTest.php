<?php

namespace Tests\cdn;

use App\cdn\provider\AliyunCdnAdapter;
use App\cdn\provider\CdnAdapterException;
use App\cdn\provider\CdnAdapterFactory;
use App\cdn\provider\CloudFrontAdapter;
use App\cdn\provider\CloudflareAdapter;
use App\cdn\provider\TencentCdnAdapter;
use PHPUnit\Framework\TestCase;

final class CdnAdapterTest extends TestCase
{
    private const ENV_KEYS = [
        'CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ZONE_ID',
        'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY',
        'ALIYUN_CDN_ACCESS_KEY_ID', 'ALIYUN_CDN_ACCESS_KEY_SECRET',
        'TENCENT_CDN_SECRET_ID', 'TENCENT_CDN_SECRET_KEY',
    ];

    protected function setUp(): void
    {
        $this->clearCredentials();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        $this->clearCredentials();
    }

    private function clearCredentials(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
        }
    }

    private function setCredentials(): void
    {
        putenv('CLOUDFLARE_API_TOKEN=t');          putenv('CLOUDFLARE_ZONE_ID=z');
        putenv('AWS_ACCESS_KEY_ID=k');             putenv('AWS_SECRET_ACCESS_KEY=s');
        putenv('ALIYUN_CDN_ACCESS_KEY_ID=a');      putenv('ALIYUN_CDN_ACCESS_KEY_SECRET=b');
        putenv('TENCENT_CDN_SECRET_ID=c');         putenv('TENCENT_CDN_SECRET_KEY=d');
    }

    public function testFactoryRejectsUnknownProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CdnAdapterFactory::create('fastly');
    }

    public function testFactoryThrowsCredentialMissingWithoutEnv(): void
    {
        foreach (['cloudflare', 'cloudfront', 'aliyun', 'tencent'] as $type) {
            try {
                CdnAdapterFactory::create($type);
                $this->fail("Expected credential_missing for {$type}");
            } catch (CdnAdapterException $e) {
                $this->assertSame(CdnAdapterException::REASON_CREDENTIAL, $e->reason);
            }
        }
    }

    public function testIcpRegistrationMetadata(): void
    {
        $this->setCredentials();
        $this->assertFalse(CdnAdapterFactory::create('cloudflare')->requiresIcpRegistration());
        $this->assertFalse(CdnAdapterFactory::create('cloudfront')->requiresIcpRegistration());
        $this->assertTrue(CdnAdapterFactory::create('aliyun')->requiresIcpRegistration());
        $this->assertTrue(CdnAdapterFactory::create('tencent')->requiresIcpRegistration());
    }

    public function testCredentialsArrayAllowsConstructionWithoutEnv(): void
    {
        $this->assertInstanceOf(CloudflareAdapter::class, new CloudflareAdapter(['api_key' => 't', 'config' => ['zone_id' => 'z']]));
        $this->assertInstanceOf(CloudFrontAdapter::class, new CloudFrontAdapter(['api_key' => 'k', 'api_secret' => 's']));
        $this->assertInstanceOf(AliyunCdnAdapter::class, new AliyunCdnAdapter(['api_key' => 'a', 'api_secret' => 'b']));
        $this->assertInstanceOf(TencentCdnAdapter::class, new TencentCdnAdapter(['api_key' => 'c', 'api_secret' => 'd']));
    }

    public function testFactoryAcceptsCredentialsArray(): void
    {
        $adapter = CdnAdapterFactory::create('cloudflare', ['api_key' => 't', 'config' => ['zone_id' => 'z']]);
        $this->assertInstanceOf(CloudflareAdapter::class, $adapter);
    }

    public function testFactoryReturnsCorrectAdapterTypes(): void
    {
        $this->setCredentials();
        $this->assertInstanceOf(CloudflareAdapter::class, CdnAdapterFactory::create('cloudflare'));
        $this->assertInstanceOf(CloudFrontAdapter::class, CdnAdapterFactory::create('cloudfront'));
        $this->assertInstanceOf(AliyunCdnAdapter::class, CdnAdapterFactory::create('aliyun'));
        $this->assertInstanceOf(TencentCdnAdapter::class, CdnAdapterFactory::create('tencent'));
    }

    public function testAliyunSignatureMatchesOfficialExample(): void
    {
        // 阿里云官方文档签名示例（GET + DescribeRegions）
        $params = [
            'Action'           => 'DescribeRegions',
            'AccessKeyId'      => 'testid',
            'Format'           => 'XML',
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureNonce'   => '3ee8c1b8-83d3-44af-a94f-4e0ad82fd6cf',
            'SignatureVersion' => '1.0',
            'Timestamp'        => '2016-02-23T12:46:24Z',
            'Version'          => '2014-05-26',
        ];
        $this->assertSame('OLeaidS1JvxuMvnyHOwuJ+uX5qY=', AliyunCdnAdapter::sign($params, 'testsecret', 'GET'));
    }

    public function testAliyunPercentEncode(): void
    {
        $this->assertSame('a%20b~c%2Ad', AliyunCdnAdapter::percentEncode('a b~c*d'));
    }

    public function testTencentSignatureFormat(): void
    {
        $headers = TencentCdnAdapter::sign(
            'AKIDexample',
            'secretKey',
            'PurgeUrlsCache',
            '{"Urls":["https://cdn.example.com/a"]}',
            1551113065
        );

        $this->assertSame('PurgeUrlsCache', $headers['X-TC-Action']);
        $this->assertSame('2018-06-06', $headers['X-TC-Version']);
        $this->assertSame('1551113065', $headers['X-TC-Timestamp']);
        $this->assertStringStartsWith('TC3-HMAC-SHA256 Credential=AKIDexample/2019-02-25/cdn/tc3_request, SignedHeaders=content-type;host;x-tc-action, Signature=', $headers['Authorization']);
        $signature = substr($headers['Authorization'], strrpos($headers['Authorization'], 'Signature=') + 10);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
    }

    public function testTencentSignatureDeterministic(): void
    {
        $a = TencentCdnAdapter::sign('id', 'key', 'AddCdnDomain', '{}', 100);
        $b = TencentCdnAdapter::sign('id', 'key', 'AddCdnDomain', '{}', 100);
        $this->assertSame($a['Authorization'], $b['Authorization']);
    }

    public function testIcpReasonDetection(): void
    {
        $this->assertSame(CdnAdapterException::REASON_ICP, CdnAdapterException::icpReason('InvalidDomain.ICPDomain', ''));
        $this->assertSame(CdnAdapterException::REASON_ICP, CdnAdapterException::icpReason('', '域名未备案'));
        $this->assertSame(CdnAdapterException::REASON_ICP, CdnAdapterException::icpReason('', 'Domain not icp registered'));
        $this->assertSame('', CdnAdapterException::icpReason('InvalidParameter', 'bad request'));
    }

    public function testAliyunCreateDomainIcpRejectionMapsToIcpReason(): void
    {
        $this->setCredentials();
        $adapter = new AliyunCdnAdapter();
        $this->injectHttp($adapter, 'post', new \GuzzleHttp\Psr7\Response(200, [], '{"Code":"InvalidDomain.ICPDomain","Message":"域名未备案"}'));

        try {
            $adapter->createDomain($this->cdn());
            $this->fail('Expected CdnAdapterException for ICP rejection');
        } catch (CdnAdapterException $e) {
            $this->assertSame(CdnAdapterException::REASON_ICP, $e->reason);
        }
    }

    public function testAliyunCreateDomainGenericErrorHasNoIcpReason(): void
    {
        $this->setCredentials();
        $adapter = new AliyunCdnAdapter();
        $this->injectHttp($adapter, 'post', new \GuzzleHttp\Psr7\Response(400, [], '{"Code":"InvalidParameter","Message":"bad request"}'));

        try {
            $adapter->createDomain($this->cdn());
            $this->fail('Expected CdnAdapterException for API error');
        } catch (CdnAdapterException $e) {
            $this->assertSame('', $e->reason);
        }
    }

    public function testTencentCreateDomainIcpRejectionMapsToIcpReason(): void
    {
        $this->setCredentials();
        $adapter = new TencentCdnAdapter();
        $this->injectHttp($adapter, 'post', new \GuzzleHttp\Psr7\Response(200, [], '{"Response":{"Error":{"Code":"InvalidParameter.DomainNotIcp","Message":"域名未备案"}}}'));

        try {
            $adapter->createDomain($this->cdn());
            $this->fail('Expected CdnAdapterException for ICP rejection');
        } catch (CdnAdapterException $e) {
            $this->assertSame(CdnAdapterException::REASON_ICP, $e->reason);
        }
    }

    public function testTencentPurgeCacheIdempotentCalls(): void
    {
        $this->setCredentials();
        $adapter = new TencentCdnAdapter();
        $calls = [];
        $client = \Mockery::mock(\GuzzleHttp\Client::class);
        $client->shouldReceive('post')->twice()->andReturnUsing(function (...$args) use (&$calls) {
            $calls[] = $args[1]['body'];
            return new \GuzzleHttp\Psr7\Response(200, [], '{"Response":{"TaskId":"1"}}');
        });
        $this->injectClient($adapter, $client);

        $urls = ['https://cdn.example.com/a', 'https://cdn.example.com/b'];
        $adapter->purgeCache($this->cdn(), $urls);
        $adapter->purgeCache($this->cdn(), $urls);

        // 同 URL 集合重复提交：请求体完全一致（服务商侧幂等）
        $this->assertCount(2, $calls);
        $this->assertSame($calls[0], $calls[1]);
        $this->assertStringContainsString('cdn.example.com/a', $calls[0]);
    }

    public function testCloudflarePurgeCacheBatchesBy30(): void
    {
        $this->setCredentials();
        $adapter = new CloudflareAdapter();
        $chunks = [];
        $client = \Mockery::mock(\GuzzleHttp\Client::class);
        $client->shouldReceive('request')->andReturnUsing(function (...$args) use (&$chunks) {
            $chunks[] = $args[2]['json']['files'];
            return new \GuzzleHttp\Psr7\Response(200, [], '{"success":true,"result":[]}');
        });
        $this->injectClient($adapter, $client);

        $urls = array_map(fn (int $i) => "https://cdn.example.com/f{$i}", range(1, 65));
        $result = $adapter->purgeCache($this->cdn(), $urls);

        $this->assertSame(65, $result['purged']);
        $this->assertCount(3, $chunks);
        $this->assertSame([30, 30, 5], array_map('count', $chunks));
        // 分批不丢 URL、不重复
        $all = array_merge(...$chunks);
        $this->assertSame($all, array_unique($all));
        $this->assertCount(65, $all);
    }

    private function cdn(): \App\cdn\model\ResourceCdn
    {
        $cdn = new \App\cdn\model\ResourceCdn();
        $cdn->cdn_domain = 'cdn.example.com';
        $cdn->origin_type = 'server';
        $cdn->origin_value = 'origin.example.com';
        return $cdn;
    }

    private function injectHttp(object $adapter, string $method, $response): void
    {
        $client = \Mockery::mock(\GuzzleHttp\Client::class);
        $client->shouldReceive($method)->once()->andReturn($response);
        $this->injectClient($adapter, $client);
    }

    private function injectClient(object $adapter, $client): void
    {
        $ref = new \ReflectionProperty($adapter, 'http');
        $ref->setValue($adapter, $client);
    }
}
