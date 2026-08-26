<?php

namespace Tests\grpc;

use App\grpc\EtcdRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class EtcdRegistryTest extends TestCase
{
    /**
     * @param array<int, Response> $queue
     * @return array{0: EtcdRegistry, 1: MockHandler}
     */
    private function registryWithMock(array $queue): array
    {
        $mock = new class($queue) extends MockHandler {
            public array $requests = [];

            public function __invoke(\Psr\Http\Message\RequestInterface $request, array $options): \GuzzleHttp\Promise\PromiseInterface
            {
                $this->requests[] = [
                    'path' => $request->getUri()->getPath(),
                    'body' => json_decode((string) $request->getBody(), true) ?: [],
                ];
                return parent::__invoke($request, $options);
            }
        };
        $registry = new EtcdRegistry('http://etcd.test:2379', 'cloud', 15);
        $ref = new \ReflectionProperty(EtcdRegistry::class, 'http');
        $ref->setAccessible(true);
        $ref->setValue($registry, new Client([
            'handler' => HandlerStack::create($mock),
            'timeout' => 1.0,
        ]));
        return [$registry, $mock];
    }

    public function testRegisterGrantsLeaseThenPutsKey(): void
    {
        [$registry, $mock] = $this->registryWithMock([
            new Response(200, [], json_encode(['ID' => '987'])),
            new Response(200, [], json_encode(['header' => []])),
        ]);

        $lease = $registry->register('kvm-server', '3.0.1', ['127.0.0.1:50051'], ['app' => 'infrastructure-kvm']);

        $this->assertSame(987, $lease);
        $this->assertCount(2, $mock->requests);
        $this->assertSame('/v3/lease/grant', $mock->requests[0]['path']);
        $this->assertSame('15', $mock->requests[0]['body']['TTL']);
        $this->assertSame('/v3/kv/put', $mock->requests[1]['path']);

        $key = base64_decode($mock->requests[1]['body']['key']);
        $this->assertStringStartsWith('/ecat/services/cloud/kvm-server/', $key);
        $this->assertSame('987', $mock->requests[1]['body']['lease']);
        $value = json_decode(base64_decode($mock->requests[1]['body']['value']), true);
        $this->assertSame('kvm-server', $value['name']);
        $this->assertSame('3.0.1', $value['version']);
        $this->assertSame(['127.0.0.1:50051'], $value['endpoints']);
    }

    public function testKeepaliveSendsLeaseId(): void
    {
        [$registry, $mock] = $this->registryWithMock([new Response(200, [], json_encode(['header' => [], 'result' => ['ID' => '1']]))]);

        $registry->keepalive(42);

        $request = $mock->getLastRequest();
        $this->assertSame('/v3/lease/keepalive', $request->getUri()->getPath());
        $this->assertSame('42', json_decode((string) $request->getBody(), true)['ID']);
    }

    public function testDiscoverParsesB64ValuesAndFiltersByPrefix(): void
    {
        $value = base64_encode(json_encode([
            'name' => 'kvm-server',
            'version' => '3.0.1',
            'endpoints' => ['127.0.0.1:50051'],
            'metadata' => ['app' => 'infrastructure-kvm'],
        ]));
        [$registry, $mock] = $this->registryWithMock([new Response(200, [], json_encode([
            'header' => [],
            'kvs' => [
                ['key' => base64_encode('/ecat/services/cloud/kvm-server/u1'), 'value' => $value],
            ],
        ]))]);

        $instances = $registry->discover('kvm-server');

        $this->assertCount(1, $instances);
        $this->assertSame('kvm-server', $instances[0]['name']);
        $this->assertSame(['127.0.0.1:50051'], $instances[0]['endpoints']);

        $request = $mock->getLastRequest();
        $this->assertSame('/v3/kv/range', $request->getUri()->getPath());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('/ecat/services/cloud/kvm-server/', base64_decode($body['key']));
    }

    public function testDeregisterDeletesPrefixRange(): void
    {
        [$registry, $mock] = $this->registryWithMock([new Response(200, [], json_encode(['header' => []]))]);

        $registry->deregister('service');

        $request = $mock->getLastRequest();
        $this->assertSame('/v3/kv/deleterange', $request->getUri()->getPath());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('/ecat/services/cloud/service/', base64_decode($body['key']));
        $this->assertNotEmpty(base64_decode($body['range_end']));
    }
}
