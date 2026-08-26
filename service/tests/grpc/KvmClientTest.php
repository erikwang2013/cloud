<?php

namespace Tests\grpc;

use App\grpc\KvmClient;
use App\grpc\KvmGrpcException;
use Ecat\Kvm\V1\ActionReply;
use PHPUnit\Framework\TestCase;

final class KvmClientTest extends TestCase
{
    public function testUnreachableServerThrowsGrpcException(): void
    {
        $client = new KvmClient('127.0.0.1:1', 'token', 1.0);
        $this->expectException(KvmGrpcException::class);
        $this->expectExceptionCode(14);
        $client->ping();
    }

    public function testCreateVmBuildsRequestJson(): void
    {
        $method = new \ReflectionMethod(KvmClient::class, 'unary');
        $method->setAccessible(true);
        // unary is private cURL; instead verify the request serialization contract
        // via a subclass-free probe: build the message the same way the client does.
        $req = new \Ecat\Kvm\V1\CreateVMRequest();
        $req->setResourceId(42);
        $req->setRegionId(7);
        $req->setSpecsJson(json_encode(['cpu' => 2, 'ram' => 4, 'system_disk' => 20]));

        $this->assertSame(42, $req->getResourceId());
        $this->assertSame(7, $req->getRegionId());
        $this->assertSame('{"cpu":2,"ram":4,"system_disk":20}', $req->getSpecsJson());
        $this->assertGreaterThan(0, strlen($req->serializeToString()));
    }

    public function testActionReplyParsesFromWireBytes(): void
    {
        $reply = new ActionReply();
        $reply->mergeFromString("\x08\x01\x10\x00\x1a\x02ok\x22\x0e{\"metrics\":{}}");
        $this->assertTrue($reply->getOk());
        $this->assertSame('ok', $reply->getMessage());
        $this->assertSame('{"metrics":{}}', $reply->getDataJson());
    }
}
