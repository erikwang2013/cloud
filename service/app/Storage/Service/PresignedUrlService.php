<?php
namespace App\Storage\Service;

use App\Storage\Model\StorageBucket;

class PresignedUrlService
{
    private int $defaultExpiry = 3600;

    public function generateUploadUrl(StorageBucket $bucket, string $key, string $contentType = 'application/octet-stream', ?int $expiresSeconds = null): string
    {
        $provider = new \App\Storage\Provider\S3StorageProvider();
        $client  = $this->getClientForBucket($provider, $bucket);

        $cmd = $client->getCommand('PutObject', [
            'Bucket'      => $bucket->bucket_name,
            'Key'         => $key,
            'ContentType' => $contentType,
        ]);

        $expires = $expiresSeconds ?? $this->defaultExpiry;

        return (string) $client->createPresignedRequest($cmd, "+{$expires} seconds")->getUri();
    }

    public function generateDownloadUrl(StorageBucket $bucket, string $key, ?int $expiresSeconds = null): string
    {
        $provider = new \App\Storage\Provider\S3StorageProvider();
        $client  = $this->getClientForBucket($provider, $bucket);

        $cmd = $client->getCommand('GetObject', [
            'Bucket' => $bucket->bucket_name,
            'Key'    => $key,
        ]);

        $expires = $expiresSeconds ?? $this->defaultExpiry;

        return (string) $client->createPresignedRequest($cmd, "+{$expires} seconds")->getUri();
    }

    private function getClientForBucket($provider, StorageBucket $bucket): \Aws\S3\S3Client
    {
        $ref = new \ReflectionMethod($provider, 'getClient');
        return $ref->invoke($provider, [
            'endpoint' => $bucket->endpoint,
            'region'   => $bucket->region,
        ]);
    }
}
