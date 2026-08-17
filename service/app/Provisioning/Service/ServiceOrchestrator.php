<?php
namespace App\Provisioning\Service;

use App\Provisioning\Model\Disk;
use App\Provisioning\Model\FirewallService;
use App\Provisioning\Model\HostMachine;
use App\Provisioning\Model\IpAllocation;
use App\Provisioning\Model\IpPool;
use App\Provisioning\Model\NetworkService;
use App\Provisioning\Model\ProvisionTask;
use App\Provisioning\Model\Resource;
use App\Provisioning\Model\SwitchService;
use App\Provisioning\Provider\Kvm\KvmDriverInterface;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * KVM 服务编排：为 VM 建网络/防火墙/交换器三条隔离服务记录 + IP 分配 + 磁盘，
 * 再按序调驱动落宿主。DB 与外部副作用分离：驱动失败抛异常由上层转为 retryable。
 */
class ServiceOrchestrator
{
    public function provision(HostMachine $host, Resource $resource, ProvisionTask $task, KvmDriverInterface $driver, array $specs): array
    {
        $vmId      = 'kvm-' . $resource->id;
        $bridge    = 'br-vm' . $resource->id;
        $fwTable   = 'fw-vm' . $resource->id;
        $vethHost  = 'veth' . $resource->id . 'a';
        $vethGuest = 'veth' . $resource->id . 'b';
        $rules     = $this->defaultRules();
        $diskSize  = (int) ($specs['system_disk'] ?? 20);

        $ip = $this->allocateIp($host->id, $resource->id);

        try {
            DB::transaction(function () use ($host, $resource, $vmId, $bridge, $fwTable, $vethHost, $vethGuest, $rules, $diskSize, $ip) {
                $net = NetworkService::create([
                    'host_machine_id' => $host->id,
                    'resource_id'     => $resource->id,
                    'vm_id'           => $vmId,
                    'bridge_name'     => $bridge,
                    'subnet'          => null, // Phase 2 按 IP 池网段生成
                    'gateway_ip'      => $ip->ipPool->gateway,
                    'status'          => 'creating',
                ]);
                FirewallService::create([
                    'host_machine_id' => $host->id,
                    'resource_id'     => $resource->id,
                    'vm_id'           => $vmId,
                    'table_name'      => $fwTable,
                    'default_policy'  => 'drop',
                    'rules'           => $rules,
                    'status'          => 'creating',
                ]);
                SwitchService::create([
                    'host_machine_id'    => $host->id,
                    'resource_id'        => $resource->id,
                    'vm_id'              => $vmId,
                    'network_service_id' => $net->id,
                    'veth_host'          => $vethHost,
                    'veth_guest'         => $vethGuest,
                    'mac_address'        => $this->macFromId($resource->id),
                    'status'             => 'creating',
                ]);
                Disk::create([
                    'resource_id'     => $resource->id,
                    'host_machine_id' => $host->id,
                    'vm_id'           => $vmId,
                    'size_gb'         => $diskSize,
                    'disk_type'       => 'system',
                    'storage_pool'    => $host->storage_pool,
                    'device_path'     => 'vda',
                    'status'          => 'creating',
                ]);
            });

            $driver->createBridge($bridge);
            $driver->createVeth($vethHost, $vethGuest, $bridge, $this->macFromId($resource->id));
            $createdVm = $driver->createVm([
                'vmId'   => $vmId,
                'cpu'    => (int) ($specs['cpu'] ?? 2),
                'ram'    => (int) ($specs['ram'] ?? 2) * 1024,
                'mac'    => $this->macFromId($resource->id),
                'bridge' => $bridge,
            ]);
            $driver->attachDisk($createdVm, "/var/lib/libvirt/images/{$vmId}.qcow2", $diskSize);
            $driver->applyFirewall($fwTable, 'drop', $rules);
            $driver->startVm($createdVm);
        } catch (\Throwable $e) {
            $this->cleanup($host, $resource, $driver);
            throw $e;
        }

        NetworkService::where('resource_id', $resource->id)->update(['status' => 'active']);
        FirewallService::where('resource_id', $resource->id)->update(['status' => 'active']);
        SwitchService::where('resource_id', $resource->id)->update(['status' => 'active']);
        Disk::where('resource_id', $resource->id)->update(['status' => 'active']);

        return ['vm_id' => $vmId, 'ip_address' => $ip->ip_address, 'bridge' => $bridge];
    }

    /** 释放 VM 全部服务：驱动清理 + 记录删除 + IP/磁盘释放 */
    public function release(HostMachine $host, Resource $resource, KvmDriverInterface $driver): void
    {
        $net  = NetworkService::where('resource_id', $resource->id)->first();
        $fw   = FirewallService::where('resource_id', $resource->id)->first();
        $sw   = SwitchService::where('resource_id', $resource->id)->first();
        $disk = Disk::where('resource_id', $resource->id)->first();

        if ($sw) {
            $driver->removeVeth($sw->veth_host);
        }
        if ($net) {
            $driver->removeBridge($net->bridge_name);
        }
        if ($fw) {
            $driver->removeFirewall($fw->table_name);
        }
        if ($disk && $disk->vm_id) {
            $driver->destroyVm($disk->vm_id);
        }

        IpAllocation::where('resource_id', $resource->id)
            ->whereNull('released_at')
            ->update(['released_at' => date('Y-m-d H:i:s')]);
        Disk::where('resource_id', $resource->id)->update(['status' => 'destroyed']);
        NetworkService::where('resource_id', $resource->id)->delete();
        FirewallService::where('resource_id', $resource->id)->delete();
        SwitchService::where('resource_id', $resource->id)->delete();
    }

    /** 行锁分配 IP：池锁 + 自增 + 线性挑可用地址（与 ProxmoxProvider 同模式） */
    public function allocateIp(int $hostMachineId, ?int $resourceId = null): IpAllocation
    {
        return DB::transaction(function () use ($hostMachineId, $resourceId) {
            $pool = IpPool::where('host_machine_id', $hostMachineId)
                ->whereRaw('used_count < total_count')
                ->lockForUpdate()
                ->firstOrFail();

            $pool->increment('used_count');

            return IpAllocation::create([
                'ip_pool_id'   => $pool->id,
                'resource_id'  => $resourceId,
                'ip_address'   => $this->pickIp($pool),
                'type'         => 'primary',
                'allocated_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    private function pickIp(IpPool $pool): string
    {
        $allocated = IpAllocation::where('ip_pool_id', $pool->id)
            ->whereNull('released_at')
            ->pluck('ip_address')
            ->toArray();

        $start = ip2long($pool->ip_start);
        $end   = ip2long($pool->ip_end);
        for ($i = $start; $i <= $end; $i++) {
            $ip = long2ip($i);
            if (!in_array($ip, $allocated)) {
                return $ip;
            }
        }
        throw new \RuntimeException('No available IP in pool');
    }

    private function defaultRules(): array
    {
        // 骨架默认：允许已建立连接与入站 SSH，其余按 default drop（每 VM 独立表）
        return [
            ['direction' => 'inbound',  'protocol' => 'tcp', 'port' => 22,   'cidr' => '0.0.0.0/0', 'action' => 'accept'],
            ['direction' => 'inbound',  'protocol' => 'tcp', 'port' => null, 'cidr' => '0.0.0.0/0', 'action' => 'accept', 'state' => 'established,related'],
        ];
    }

    private function macFromId(int $resourceId): string
    {
        return sprintf('02:00:00:%02x:%02x:%02x', ($resourceId >> 16) & 0xff, ($resourceId >> 8) & 0xff, $resourceId & 0xff);
    }

    private function cleanup(HostMachine $host, Resource $resource, KvmDriverInterface $driver): void
    {
        try {
            $this->release($host, $resource, $driver);
        } catch (\Throwable $e) {
            // 清理尽力而为；外层已捕获原异常转 retryable
            \support\Log::warning("KVM cleanup failed for resource {$resource->id}: {$e->getMessage()}");
        }
    }
}
