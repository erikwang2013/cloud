<?php
namespace App\Storage\Provider;

use App\Provisioning\Service\ProviderInterface;
use App\Provisioning\Model\ProvisionTask;
use App\Provisioning\Model\ProvisionResult;
use App\Provisioning\Model\Resource;
use App\Provisioning\Model\ResourceStatus;
use App\Storage\Model\StorageBucket;

class S3StorageProvider implements ProviderInterface
{
    protected bool $pathStyle = false;
    protected bool $verifySsl = true;

    public function create(ProvisionTask $task): ProvisionResult
    {
        $params = json_decode($task->params, true);
        $bucketName = $params['bucket_name'] ?? 'bucket-' . $task->resource_id;
        $region     = $params['region'] ?? 'us-east-1';
        $quotaGb    = $params['quota_gb'] ?? 10;

        try {
            $client = $this->getClient($params);

            $client->createBucket([
                'Bucket' => $bucketName,
            ]);

            $bucket = StorageBucket::where('resource_id', $task->resource_id)->first();
            if ($bucket) {
                $bucket->update([
                    'bucket_name' => $bucketName,
                    'endpoint'    => $params['endpoint'] ?? '',
                    'region'      => $region,
                    'quota_gb'    => $quotaGb,
                    'status'      => 'active',
                ]);
            }

            return ProvisionResult::success([
                'bucket_name' => $bucketName,
                'region'      => $region,
                'endpoint'    => $params['endpoint'] ?? '',
            ]);
        } catch (\Throwable $e) {
            return ProvisionResult::retryable('Bucket creation failed: ' . $e->getMessage());
        }
    }

    public function destroy(Resource $resource): ProvisionResult
    {
        $bucket = StorageBucket::where('resource_id', $resource->id)->first();
        if (!$bucket) {
            return ProvisionResult::success([]);
        }

        try {
            $client = $this->getClient([
                'endpoint' => $bucket->endpoint,
                'region'   => $bucket->region,
            ]);

            $objects = $client->listObjectsV2(['Bucket' => $bucket->bucket_name]);
            if (!empty($objects['Contents'])) {
                $client->deleteObjects([
                    'Bucket' => $bucket->bucket_name,
                    'Delete' => ['Objects' => array_map(fn($o) => ['Key' => $o['Key']], $objects['Contents'])],
                ]);
            }

            $client->deleteBucket(['Bucket' => $bucket->bucket_name]);
            $bucket->update(['status' => 'deleted']);

            return ProvisionResult::success([]);
        } catch (\Throwable $e) {
            return ProvisionResult::retryable('Bucket deletion failed: ' . $e->getMessage());
        }
    }

    public function status(Resource $resource): ResourceStatus
    {
        $bucket = StorageBucket::where('resource_id', $resource->id)->first();
        $rs = new ResourceStatus();
        $rs->status  = $bucket ? $bucket->status : 'unknown';
        $rs->metrics = $bucket ? ['used_gb' => $bucket->used_gb, 'quota_gb' => $bucket->quota_gb] : [];
        return $rs;
    }

    public function renew(Resource $resource, int $months): ProvisionResult
    {
        return ProvisionResult::success([]);
    }

    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult
    {
        $bucket = StorageBucket::where('resource_id', $resource->id)->first();
        if ($bucket && isset($newSpecs['quota_gb'])) {
            $bucket->update(['quota_gb' => $newSpecs['quota_gb']]);
        }
        return ProvisionResult::success(['message' => 'Storage quota updated']);
    }

    public function consoleUrl(Resource $resource): string
    {
        return '';
    }

    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult
    {
        return ProvisionResult::success([]);
    }

    public function createDisk(ProvisionTask $task): ProvisionResult
    {
        return ProvisionResult::success([]);
    }

    public function createIp(ProvisionTask $task): ProvisionResult
    {
        return ProvisionResult::success([]);
    }

    public function getClient(array $config): \Aws\S3\S3Client
    {
        $args = [
            'version'     => 'latest',
            'region'      => $config['region'] ?? 'us-east-1',
            'credentials' => [
                'key'    => $config['access_key'] ?? getenv('AWS_ACCESS_KEY_ID'),
                'secret' => $config['secret_key'] ?? getenv('AWS_SECRET_ACCESS_KEY'),
            ],
        ];

        if ($this->pathStyle && !empty($config['endpoint'])) {
            $args['endpoint']               = $config['endpoint'];
            $args['use_path_style_endpoint'] = true;
        }

        return new \Aws\S3\S3Client($args);
    }
}
