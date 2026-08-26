<?php
namespace App\provisioning\provider\kvm;

use App\provisioning\model\Disk;
use App\provisioning\model\DiskResize;
use App\provisioning\model\HostMachine;
use App\provisioning\model\ProvisionResult;
use App\provisioning\model\ProvisionTask;
use App\provisioning\model\Resource;
use App\provisioning\model\resourcestatus;
use App\provisioning\provider\HostSelector;
use App\provisioning\service\ProviderInterface;
use App\provisioning\service\ServiceOrchestrator;

/**
 * KVM 提供商（libvirt 后端骨架）：创建走 区域分布式锁 → 选 KVM 宿主机 →
 * ServiceOrchestrator 编排（网络/防火墙/交换器服务 + IP + 磁盘）→ 驱动落宿主。
 * 与 ProxmoxProvider 并存，ProviderFactory 按 product.provider='kvm' 切换。
 */
class KvmProvider implements ProviderInterface
{
    private ServiceOrchestrator $orchestrator;
    private KvmDriverInterface $driver;
    private HostSelector $selector;

    public function __construct(?KvmDriverInterface $driver = null)
    {
        $this->orchestrator = new ServiceOrchestrator();
        $this->driver       = $driver ?? new VirshDriver();
        $this->selector     = new HostSelector();
    }

    public function create(ProvisionTask $task): ProvisionResult
    {
        $params = json_decode($task->params, true);
        $specs  = $params['specs'] ?? [];

        // 区域级分布式锁串行化同区域并发创建：容量选择是 check-then-act，无锁会超售
        $lockKey   = "lock:provision:region:{$task->region_id}:kvm";
        $lockToken = bin2hex(random_bytes(8));
        if (!\support\Redis::set($lockKey, $lockToken, 'EX', 300, 'NX')) {
            return ProvisionResult::retryable('Provisioning in progress for this region');
        }

        try {
            $host = $this->selector->select($task->region_id, $specs, 'kvm');
            $resource = Resource::findOrFail($task->resource_id);
            if ($this->driver instanceof VirshDriver) {
                $this->driver->setHost($host);
            }
            $info = $this->orchestrator->provision($host, $resource, $task, $this->driver, $specs);
            $this->recalculateHostAllocation($host);
            return ProvisionResult::success($info);
        } catch (\Exception $e) {
            return ProvisionResult::retryable($e->getMessage());
        } finally {
            \support\Redis::eval(
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                1,
                $lockKey,
                $lockToken
            );
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

    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult
    {
        // TODO: 真机 virsh setvcpus/setmaxmem（Phase 2）；骨架只落 DB 记账
        if (isset($newSpecs['cpu'])) {
            $specs = $resource->specs;
            $specs['cpu'] = $newSpecs['cpu'];
            $resource->specs = $specs;
            $resource->save();
        }
        if (isset($newSpecs['ram'])) {
            $specs = $resource->specs;
            $specs['ram'] = $newSpecs['ram'];
            $resource->specs = $specs;
            $resource->save();
        }
        $this->recalculateHostAllocation($this->hostOf($resource));
        return ProvisionResult::success();
    }

    public function destroy(Resource $resource): ProvisionResult
    {
        try {
            $host = $this->hostOf($resource);
            if ($this->driver instanceof VirshDriver) {
                $this->driver->setHost($host);
            }
            $this->orchestrator->release($host, $resource, $this->driver);
            $this->recalculateHostAllocation($host);
            return ProvisionResult::success();
        } catch (\Exception $e) {
            return ProvisionResult::retryable($e->getMessage());
        }
    }

    public function status(Resource $resource): ResourceStatus
    {
        $status = new ResourceStatus();
        try {
            $disk = Disk::where('resource_id', $resource->id)->first();
            $host = $this->hostOf($resource);
            if ($this->driver instanceof VirshDriver) {
                $this->driver->setHost($host);
            }
            $status->status = $disk && $disk->vm_id
                ? $this->driver->status($disk->vm_id)
                : 'pending';
            $status->metrics = [];
        } catch (\Exception $e) {
            $status->status = 'error';
        }
        return $status;
    }

    public function consoleUrl(Resource $resource): string
    {
        // TODO: 真机 noVNC 接入（Phase 2）
        $disk = Disk::where('resource_id', $resource->id)->first();
        $host = $this->hostOf($resource);
        return "https://{$host->ip_address}:6080/vnc.html?vm=" . ($disk->vm_id ?? $resource->id);
    }

    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult
    {
        // TODO: 真机 qemu-img resize + virsh blockresize（Phase 2）；骨架只落记账
        try {
            $disk = Disk::where('resource_id', $resource->id)
                ->where('disk_type', 'system')
                ->firstOrFail();
            DiskResize::create([
                'disk_id'     => $disk->id,
                'old_size_gb' => $disk->size_gb,
                'new_size_gb' => $newSizeGb,
                'status'      => 'completed',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $disk->update(['size_gb' => $newSizeGb]);
            $this->recalculateHostAllocation($this->hostOf($resource));
            return ProvisionResult::success();
        } catch (\Exception $e) {
            return ProvisionResult::retryable($e->getMessage());
        }
    }

    public function createDisk(ProvisionTask $task): ProvisionResult
    {
        // TODO: 真机 qemu-img create + attach（Phase 2）；骨架只落记录
        try {
            $params = json_decode($task->params, true);
            $disk = Disk::where('resource_id', $task->resource_id)
                ->where('disk_type', 'system')
                ->firstOrFail();
            Disk::create([
                'resource_id'     => $task->resource_id,
                'host_machine_id' => $disk->host_machine_id,
                'vm_id'           => $disk->vm_id,
                'size_gb'         => $params['size_gb'],
                'disk_type'       => 'data',
                'storage_pool'    => $disk->storage_pool,
                'device_path'     => 'vdb',
                'status'          => 'active',
            ]);
            return ProvisionResult::success(['device' => 'vdb']);
        } catch (\Exception $e) {
            return ProvisionResult::retryable($e->getMessage());
        }
    }

    public function createIp(ProvisionTask $task): ProvisionResult
    {
        try {
            $disk = Disk::where('resource_id', $task->resource_id)->firstOrFail();
            // TODO: 真机附加第二张网卡（Phase 2）；骨架只分配 IP
            $ip = $this->orchestrator->allocateIp($disk->host_machine_id);
            return ProvisionResult::success(['ip' => $ip->ip_address]);
        } catch (\Exception $e) {
            return ProvisionResult::retryable($e->getMessage());
        }
    }

    private function hostOf(Resource $resource): HostMachine
    {
        $disk = Disk::where('resource_id', $resource->id)->first();
        return HostMachine::findOrFail($disk->host_machine_id);
    }

    // 与 ProxmoxProvider 相同的聚合重算：active 资源 specs 之和 + 磁盘之和，幂等
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

        $h = $host->specs; // specs 已 cast array
        $h['cpu_allocated']     = $cpu;
        $h['ram_allocated_gb']  = $ram;
        $h['disk_allocated_gb'] = $diskGb;
        $host->specs = $h;
        $host->save();
    }
}
