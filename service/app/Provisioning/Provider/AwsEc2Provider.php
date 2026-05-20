<?php
namespace App\Provisioning\Provider;

use App\Provisioning\Model\ProvisionTask;
use App\Provisioning\Model\Resource;
use App\Provisioning\Model\ProviderApi;
use App\Provisioning\Model\ProvisionResult;
use App\Provisioning\Model\ResourceStatus;
use App\Provisioning\Service\ProviderInterface;

class AwsEc2Provider implements ProviderInterface
{
    private \Aws\Ec2\Ec2Client $client;
    private ProviderApi $config;
    private string $region;

    public function __construct(ProviderApi $config, string $region = 'us-east-1')
    {
        $this->config = $config;
        $this->region = $region;
        $this->client = new \Aws\Ec2\Ec2Client([
            'version'     => 'latest',
            'region'      => $region,
            'credentials' => [
                'key'    => $config->api_key_encrypted,
                'secret' => $config->api_secret_encrypted,
            ],
        ]);
    }

    public function create(ProvisionTask $task): \App\Provisioning\Model\ProvisionResult
    {
        $params = json_decode($task->params, true);

        // Run instance
        $result = $this->client->runInstances([
            'ImageId'      => $params['image_id'] ?? 'ami-0c55b159cbfafe1f0',
            'InstanceType' => $params['instance_type'] ?? 't3.micro',
            'MinCount'     => 1,
            'MaxCount'     => 1,
            'SecurityGroupIds' => $params['security_group_ids'] ?? ['default'],
            'SubnetId'         => $params['subnet_id'] ?? '',
            'TagSpecifications' => [
                ['ResourceType' => 'instance', 'Tags' => [
                    ['Key' => 'Name', 'Value' => 'CloudPlatform-' . $task->id],
                ]],
            ],
        ]);

        $instance   = $result->get('Instances')[0];
        $instanceId = $instance['InstanceId'];

        // Wait until running
        $this->client->waitUntil('InstanceRunning', ['InstanceIds' => [$instanceId]]);

        // Get public IP
        $describe = $this->client->describeInstances(['InstanceIds' => [$instanceId]]);
        $ip       = $describe['Reservations'][0]['Instances'][0]['PublicIpAddress'] ?? '';

        return new \App\Provisioning\Model\ProvisionResult(true, [
            'provider_id' => $instanceId,
            'ip_address'  => $ip,
            'instance_type' => $params['instance_type'] ?? 't3.micro',
        ], "Instance {$instanceId} launched");
    }

    public function renew(Resource $resource, int $months): \App\Provisioning\Model\ProvisionResult
    {
        // EC2 instances are billed hourly — renewal is a no-op
        return new \App\Provisioning\Model\ProvisionResult(true, [], 'EC2 instance billing continues');
    }

    public function upgrade(Resource $resource, array $newSpecs): \App\Provisioning\Model\ProvisionResult
    {
        $instanceId = $resource->provider_id;

        // Stop instance
        $this->client->stopInstances(['InstanceIds' => [$instanceId]]);
        $this->client->waitUntil('InstanceStopped', ['InstanceIds' => [$instanceId]]);

        // Change instance type
        if (!empty($newSpecs['instance_type'])) {
            $this->client->modifyInstanceAttribute([
                'InstanceId' => $instanceId,
                'InstanceType' => ['Value' => $newSpecs['instance_type']],
            ]);
        }

        // Start instance
        $this->client->startInstances(['InstanceIds' => [$instanceId]]);
        $this->client->waitUntil('InstanceRunning', ['InstanceIds' => [$instanceId]]);

        return new \App\Provisioning\Model\ProvisionResult(true, $newSpecs, 'Instance upgraded');
    }

    public function destroy(Resource $resource): \App\Provisioning\Model\ProvisionResult
    {
        $this->client->terminateInstances(['InstanceIds' => [$resource->provider_id]]);
        return new \App\Provisioning\Model\ProvisionResult(true, [], 'Instance terminated');
    }

    public function status(Resource $resource): \App\Provisioning\Model\ResourceStatus
    {
        $result = $this->client->describeInstances(['InstanceIds' => [$resource->provider_id]]);
        $state  = $result['Reservations'][0]['Instances'][0]['State']['Name'] ?? 'unknown';

        $statusMap = [
            'running'    => 'active',
            'stopped'    => 'stopped',
            'terminated' => 'destroyed',
            'pending'    => 'provisioning',
        ];

        return new \App\Provisioning\Model\ResourceStatus($statusMap[$state] ?? $state, $state);
    }

    public function consoleUrl(Resource $resource): string
    {
        return "https://{$this->region}.console.aws.amazon.com/ec2/v2/home?region={$this->region}#Instances:instanceId={$resource->provider_id}";
    }

    public function resizeDisk(Resource $resource, int $newSizeGb): \App\Provisioning\Model\ProvisionResult
    {
        // EC2 EBS volume resize
        $describe = $this->client->describeInstances(['InstanceIds' => [$resource->provider_id]]);
        $volumeId = $describe['Reservations'][0]['Instances'][0]['BlockDeviceMappings'][0]['Ebs']['VolumeId'] ?? '';

        if ($volumeId) {
            $this->client->modifyVolume(['VolumeId' => $volumeId, 'Size' => $newSizeGb]);
        }

        return new \App\Provisioning\Model\ProvisionResult(true, ['volume_id' => $volumeId], 'Disk resize initiated');
    }

    public function createDisk(\App\Provisioning\Model\ProvisionTask $task): \App\Provisioning\Model\ProvisionResult
    {
        $params = json_decode($task->params, true);
        $result = $this->client->createVolume([
            'AvailabilityZone' => $this->region . 'a',
            'Size'             => $params['size_gb'] ?? 10,
            'VolumeType'       => $params['volume_type'] ?? 'gp3',
        ]);

        return new \App\Provisioning\Model\ProvisionResult(true, [
            'volume_id' => $result->get('VolumeId'),
        ], 'EBS volume created');
    }

    public function createIp(\App\Provisioning\Model\ProvisionTask $task): \App\Provisioning\Model\ProvisionResult
    {
        $result = $this->client->allocateAddress(['Domain' => 'vpc']);

        return new \App\Provisioning\Model\ProvisionResult(true, [
            'allocation_id' => $result->get('AllocationId'),
            'public_ip'     => $result->get('PublicIp'),
        ], 'Elastic IP allocated');
    }
}
