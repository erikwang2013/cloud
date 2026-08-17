<?php

namespace App\Grpc;

use Ecat\Kvm\V1\ActionReply;
use Ecat\Kvm\V1\CreateVMRequest;
use Ecat\Kvm\V1\PingRequest;
use Ecat\Kvm\V1\PingResponse;
use Ecat\Kvm\V1\VmStatusRequest;
use Google\Protobuf\Internal\Message;

/**
 * KVM gRPC client over cURL HTTP/2.
 *
 * The installed grpc extension (1.83) exposes no BaseStub and its low-level
 * Call API never completes against the tonic server, so we speak HTTP/2 +
 * gRPC framing directly via cURL (verified working).
 */
class KvmClient
{
    public function __construct(
        private readonly string $addr,
        private readonly string $token = '',
        private readonly float $timeoutSec = 5.0,
    ) {
    }

    public function ping(): PingResponse
    {
        return $this->unary('/ecat.kvm.v1.KvmService/Ping', new PingRequest(), PingResponse::class);
    }

    public function createVM(int $resourceId, int $regionId, array $specs = []): ActionReply
    {
        $req = new CreateVMRequest();
        $req->setResourceId($resourceId);
        $req->setRegionId($regionId);
        if ($specs !== []) {
            $req->setSpecsJson(json_encode($specs, JSON_UNESCAPED_UNICODE));
        }
        return $this->unary('/ecat.kvm.v1.KvmService/CreateVM', $req, ActionReply::class);
    }

    public function vmStatus(int $resourceId): ActionReply
    {
        $req = new VmStatusRequest();
        $req->setResourceId($resourceId);
        return $this->unary('/ecat.kvm.v1.KvmService/VMStatus', $req, ActionReply::class);
    }

    /**
     * @throws KvmGrpcException on transport error or non-OK gRPC status
     */
    private function unary(string $path, Message $req, string $respClass): Message
    {
        $payload = $req->serializeToString();
        $frame = "\x00" . pack('N', strlen($payload)) . $payload;

        $headers = [];
        $ch = curl_init('http://' . $this->addr . $path);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $frame);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) ceil($this->timeoutSec));
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$headers) {
            $headers[] = trim($line);
            return strlen($line);
        });
        $httpHeaders = ['content-type: application/grpc', 'te: trailers'];
        if ($this->token !== '') {
            $httpHeaders[] = 'authorization: Bearer ' . $this->token;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new KvmGrpcException('gRPC transport error (' . $errno . ')', 14);
        }

        $grpcStatus = 0;
        $grpcMessage = '';
        foreach ($headers as $h) {
            if (stripos($h, 'grpc-status:') === 0) {
                $grpcStatus = (int) trim(substr($h, 12));
            } elseif (stripos($h, 'grpc-message:') === 0) {
                $grpcMessage = rawurldecode(trim(substr($h, 13)));
            }
        }
        if ($httpCode !== 200 || $grpcStatus !== 0) {
            throw new KvmGrpcException($grpcMessage ?: 'gRPC status ' . $grpcStatus, $grpcStatus);
        }
        if (!is_string($body) || strlen($body) < 5) {
            throw new KvmGrpcException('empty gRPC response', 2);
        }
        $frameLen = unpack('N', substr($body, 1, 4))[1];
        $resp = new $respClass();
        $resp->mergeFromString(substr($body, 5, $frameLen));
        return $resp;
    }
}
