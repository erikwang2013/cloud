<?php
namespace App\Provisioning\Provider;

use App\Provisioning\Service\ProviderInterface;
use App\Provisioning\Model\ProvisionTask;
use App\Provisioning\Model\ProvisionResult;
use App\Provisioning\Model\Resource;
use App\Provisioning\Model\ResourceStatus;
use App\Provisioning\Model\HostMachine;
use App\Provisioning\Model\IpPool;
use App\Provisioning\Model\IpAllocation;
use App\Provisioning\Model\Disk;
use App\Provisioning\Model\DiskResize;
use Illuminate\Database\Capsule\Manager as DB;

class ProxmoxProvider implements ProviderInterface
{
    private HostSelector $selector;

    public function __construct()
    {
        $this->selector = new HostSelector();
    }

    public function create(ProvisionTask $task): ProvisionResult
    {
        $params = json_decode($task->params, true);
        $specs  = $params['specs'] ?? [];

        try {
            $host = $this->selector->select($task->region_id, $specs);
            $ip = $this->allocateIp($host->id);

            $api   = new ProxmoxApi($host);
            $vmid  = $api->nextVmid();
            $vmCfg = [
                'vmid'      => $vmid,
                'name'      => "vm-{$task->order_id}-{$task->order_item_id}",
                'cores'     => $specs['cpu'] ?? 2,
                'memory'    => ($specs['ram'] ?? 2) * 1024,
                'net0'      => 'virtio,bridge=vmbr0',
                'ipconfig0' => "ip={$ip->ip_address},gw={$ip->ipPool->gateway}",
                'ostype'    => 'l26',
            ];

            $api->post("/nodes/{$host->proxmox_node}/qemu", $vmCfg);

            $diskSize = $specs['system_disk'] ?? 20;
            $api->post("/nodes/{$host->proxmox_node}/qemu/{$vmid}/config", [
                'scsi0' => "{$host->storage_pool}:{$diskSize}G",
            ]);

            $api->post("/nodes/{$host->proxmox_node}/qemu/{$vmid}/status/start");

            $resource = Resource::find($task->resource_id);
            Disk::create([
                'resource_id'     => $resource->id,
                'host_machine_id' => $host->id,
                'vm_id'           => $vmid,
                'size_gb'         => $diskSize,
                'disk_type'       => 'system',
                'storage_pool'    => $host->storage_pool,
                'device_path'     => 'scsi0',
                'status'          => 'active',
            ]);

            $this->recalculateHostAllocation($host);

            return ProvisionResult::success([
                'vmid'       => $vmid,
                'host_id'    => $host->id,
                'ip_address' => $ip->ip_address,
            ]);

        } catch (\Exception $e) {
            return ProvisionResult::retryable($e->getMessage());
        }
    }

    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult
    {
        try {
            $disk = Disk::where('resource_id', $resource->id)->firstOrFail();
            $host = HostMachine::findOrFail($disk->host_machine_id);
            $api  = new ProxmoxApi($host);

            if (isset($newSpecs['cpu'])) {
                $api->put("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}/config", [
                    'cores' => $newSpecs['cpu'],
                ]);
                $specs = $resource->specs;
                $specs['cpu'] = $newSpecs['cpu'];
                $resource->specs = $specs;
                $resource->save();
            }

            if (isset($newSpecs['ram'])) {
                $api->put("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}/config", [
                    'memory' => $newSpecs['ram'] * 1024,
                ]);
                $specs = $resource->specs;
                $specs['ram'] = $newSpecs['ram'];
                $resource->specs = $specs;
                $resource->save();
            }

            // 记账从增量改聚合重算：重试/部分失败不双重计数（增量叠加在 API 成功、DB 保存之间崩溃会虚增）
            $this->recalculateHostAllocation($host);

            return ProvisionResult::success();
        } catch (\Exception $e) {
            return ProvisionResult::retryable($e->getMessage());
        }
    }

    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult
    {
        try {
            $disk = Disk::where('resource_id', $resource->id)
                ->where('disk_type', 'system')
                ->firstOrFail();

            $host = HostMachine::findOrFail($disk->host_machine_id);
            $api  = new ProxmoxApi($host);

            $api->put("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}/resize", [
                'disk' => $disk->device_path,
                'size' => "{$newSizeGb}G",
            ]);

            DiskResize::create([
                'disk_id'     => $disk->id,
                'old_size_gb' => $disk->size_gb,
                'new_size_gb' => $newSizeGb,
                'status'      => 'completed',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            $disk->update(['size_gb' => $newSizeGb]);
            $this->recalculateHostAllocation($host);

            return ProvisionResult::success();
        } catch (\Exception $e) {
            return ProvisionResult::retryable($e->getMessage());
        }
    }

    public function createDisk(ProvisionTask $task): ProvisionResult
    {
        try {
            $params = json_decode($task->params, true);
            $disk   = Disk::where('resource_id', $task->resource_id)
                ->where('disk_type', 'system')
                ->firstOrFail();

            $host = HostMachine::findOrFail($disk->host_machine_id);
            $api  = new ProxmoxApi($host);

            $existingDisks = Disk::where('host_machine_id', $host->id)
                ->where('vm_id', $disk->vm_id)
                ->pluck('device_path')
                ->toArray();

            $diskNum = 1;
            while (in_array("scsi{$diskNum}", $existingDisks)) {
                $diskNum++;
            }
            $devicePath = "scsi{$diskNum}";

            $api->post("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}/config", [
                $devicePath => "{$host->storage_pool}:{$params['size_gb']}G",
            ]);

            Disk::create([
                'resource_id'     => $task->resource_id,
                'host_machine_id' => $host->id,
                'vm_id'           => $disk->vm_id,
                'size_gb'         => $params['size_gb'],
                'disk_type'       => 'data',
                'storage_pool'    => $host->storage_pool,
                'device_path'     => $devicePath,
                'status'          => 'active',
            ]);

            return ProvisionResult::success(['device' => $devicePath]);
        } catch (\Exception $e) {
            return ProvisionResult::retryable($e->getMessage());
        }
    }

    public function createIp(ProvisionTask $task): ProvisionResult
    {
        try {
            $disk = Disk::where('resource_id', $task->resource_id)->firstOrFail();
            $host = HostMachine::findOrFail($disk->host_machine_id);
            $api  = new ProxmoxApi($host);

            $ip = $this->allocateIp($host->id);

            $existingNets = $api->get("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}/config");
            $netCount = 1;
            foreach ($existingNets as $key => $val) {
                if (str_starts_with($key, 'net')) {
                    $netCount++;
                }
            }

            $api->post("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}/config", [
                "net{$netCount}"      => "virtio,bridge=vmbr0",
                "ipconfig{$netCount}" => "ip={$ip->ip_address}",
            ]);

            return ProvisionResult::success(['ip' => $ip->ip_address]);
        } catch (\Exception $e) {
            return ProvisionResult::retryable($e->getMessage());
        }
    }

    public function destroy(Resource $resource): ProvisionResult
    {
        try {
            $disk = Disk::where('resource_id', $resource->id)->firstOrFail();
            $host = HostMachine::findOrFail($disk->host_machine_id);
            $api  = new ProxmoxApi($host);

            $api->post("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}/status/stop");
            sleep(5);
            $api->delete("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}");

            IpAllocation::where('resource_id', $resource->id)->update(['released_at' => date('Y-m-d H:i:s')]);

            Disk::where('host_machine_id', $host->id)
                ->where('vm_id', $disk->vm_id)
                ->update(['status' => 'destroyed']);

            $this->recalculateHostAllocation($host);

            return ProvisionResult::success();
        } catch (\Exception $e) {
            return ProvisionResult::retryable($e->getMessage());
        }
    }

    public function renew(Resource $resource, int $months): ProvisionResult
    {
        $expiryBase = strtotime($resource->expired_at ?: date('Y-m-d H:i:s'));
        $resource->update([
            'expired_at' => date('Y-m-d H:i:s', strtotime("+{$months} months", $expiryBase)),
        ]);
        return ProvisionResult::success();
    }

    public function status(Resource $resource): ResourceStatus
    {
        try {
            $disk = Disk::where('resource_id', $resource->id)->firstOrFail();
            $host = HostMachine::findOrFail($disk->host_machine_id);
            $api  = new ProxmoxApi($host);

            $vmStatus = $api->get("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}/status/current");

            $status = new ResourceStatus();
            $status->status = $vmStatus['status'] ?? 'unknown';
            $status->metrics = [
                'cpu_percent'  => $vmStatus['cpu'] ?? 0,
                'mem_percent'  => ($vmStatus['maxmem'] ?? 1) > 0 ? ($vmStatus['mem'] ?? 0) / $vmStatus['maxmem'] * 100 : 0,
                'disk_percent' => $vmStatus['disk'] ?? 0,
            ];

            return $status;
        } catch (\Exception $e) {
            $status = new ResourceStatus();
            $status->status = 'error';
            return $status;
        }
    }

    public function consoleUrl(Resource $resource): string
    {
        $disk = Disk::where('resource_id', $resource->id)->firstOrFail();
        $host = HostMachine::findOrFail($disk->host_machine_id);
        return "https://{$host->ip_address}:8006/#v1:0:=node%2F{$host->proxmox_node}";
    }

    private function allocateIp(int $hostMachineId): IpAllocation
    {
        return DB::transaction(function () use ($hostMachineId) {
            $pool = IpPool::where('host_machine_id', $hostMachineId)
                ->whereRaw('used_count < total_count')
                ->lockForUpdate()
                ->firstOrFail();

            $pool->increment('used_count');

            return IpAllocation::create([
                'ip_pool_id'   => $pool->id,
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

    // 单一事实源聚合：active 资源 specs 之和 + active 磁盘大小之和，天然幂等（create/upgrade/destroy 重试安全）
    private function recalculateHostAllocation(HostMachine $host): void
    {
        $activeDisks = Disk::where('host_machine_id', $host->id)
            ->where('status', 'active')
            ->get();

        $cpu = $ram = 0;
        foreach ($activeDisks as $disk) {
            $resource = Resource::find($disk->resource_id);
            if (!$resource) {
                continue;
            }
            $cpu += $resource->specs['cpu'] ?? 1;
            $ram += $resource->specs['ram'] ?? 2;
        }
        $diskGb = $activeDisks->sum('size_gb');

        $h = json_decode($host->specs, true);
        $h['cpu_allocated']     = $cpu;
        $h['ram_allocated_gb']  = $ram;
        $h['disk_allocated_gb'] = $diskGb;
        $host->specs = json_encode($h);
        $host->save();
    }
}
