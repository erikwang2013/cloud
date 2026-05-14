# Phase 2: 资源交付 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 实现支付后自动开通资源、域名管理、供应商入驻与管理。Proxmox API 控制物理机创建/管理 VM、IP、磁盘。

**Architecture:** Provider 插件架构 (ResourceProvider interface)，异步 Worker 轮询 provision_tasks，事件驱动 (OrderPaid → ProvisioningService → ProviderFactory → ProxmoxProvider/AwsProvider/etc)。

**Tech Stack:** PHP 8.2+, webman redis-queue, Proxmox VE REST API, Guzzle HTTP client

---

### Task 2.1: Provisioning engine core

**Files:**
- Create: `service/app/provisioning/service/ProvisioningService.php`
- Create: `service/app/provisioning/service/ProviderFactory.php`
- Create: `service/app/provisioning/service/ProviderInterface.php`
- Create: `service/app/provisioning/model/Resource.php`
- Create: `service/app/provisioning/model/ProvisionTask.php`
- Create: `service/app/provisioning/listener/OrderPaidListener.php`
- Create: `service/app/provisioning/queue/ProvisionWorker.php`

- [ ] **Step 1: Create ProviderInterface**

```php
<?php
namespace App\Provisioning\Service;

use App\Provisioning\Model\ProvisionTask;
use App\Provisioning\Model\Resource;
use App\Provisioning\Model\ProvisionResult;

interface ProviderInterface
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // Physical server specific
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

- [ ] **Step 2: Create ProvisionResult and ResourceStatus**

```php
<?php
namespace App\Provisioning\Model;

class ProvisionResult
{
    public string $status; // success/retryable/failed
    public array $data;
    public ?string $errorMessage;

    public static function success(array $data = []): self
    {
        $r = new self();
        $r->status = 'success';
        $r->data = $data;
        return $r;
    }

    public static function retryable(string $error): self
    {
        $r = new self();
        $r->status = 'retryable';
        $r->errorMessage = $error;
        return $r;
    }

    public static function failed(string $error): self
    {
        $r = new self();
        $r->status = 'failed';
        $r->errorMessage = $error;
        return $r;
    }
}

class ResourceStatus
{
    public string $status;    // running/stopped/pending/error
    public array $metrics;   // cpu_percent, mem_percent, disk_percent, bw_usage
}
```

- [ ] **Step 3: Create ProviderFactory**

```php
<?php
namespace App\Provisioning\Service;

use App\Provisioning\Model\ProvisionTask;

class ProviderFactory
{
    private array $providers = [];

    public function register(string $productType, string $provider, callable $factory): void
    {
        $this->providers["{$productType}:{$provider}"] = $factory;
    }

    public function create(ProvisionTask $task): ProviderInterface
    {
        $key = "{$task->product_type}:{$task->provider}";

        if (!isset($this->providers[$key])) {
            throw new \RuntimeException("No provider registered for: {$key}");
        }

        return call_user_func($this->providers[$key], $task);
    }
}
```

- [ ] **Step 4: Create ProvisioningService**

```php
<?php
namespace App\Provisioning\Service;

use App\Provisioning\Model\ProvisionTask;
use App\Provisioning\Model\Resource;
use App\Order\Model\Order;
use App\Order\Model\OrderItem;
use App\Order\Model\OrderTimeline;

class ProvisioningService
{
    private ProviderFactory $factory;

    public function __construct()
    {
        $this->factory = new ProviderFactory();
        // Register providers (filled in Task 2.2)
    }

    // Called by OrderPaid event
    public function handleOrderPaid(Order $order): void
    {
        $order->update(['status' => 'provisioning']);
        OrderTimeline::create([
            'order_id' => $order->id,
            'status'   => 'provisioning',
            'operator' => 'system',
            'remark'   => 'Provisioning started',
        ]);

        foreach ($order->items as $item) {
            $task = ProvisionTask::create([
                'order_id'      => $order->id,
                'order_item_id' => $item->id,
                'resource_id'   => null,
                'product_type'  => $this->getProductType($item),
                'provider'      => $this->resolveProvider($item),
                'region_id'     => $item->region_id,
                'action'        => 'create',
                'status'        => 'pending',
                'params'        => json_encode([
                    'specs'       => $item->sku->specs,
                    'quantity'    => $item->quantity,
                    'cycle'       => $item->cycle,
                    'resource_snapshot' => $item->resource_snapshot,
                ]),
                'retry_count'    => 0,
                'next_retry_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function getProductType(OrderItem $item): string
    {
        $categoryId = $item->sku->product->category_id;
        // Map category to product type
        $map = [1 => 'server', 2 => 'ip', 3 => 'disk', 4 => 'domain'];
        return $map[$categoryId] ?? 'server';
    }

    private function resolveProvider(OrderItem $item): string
    {
        // Check if item product has a supplier -> third party
        // Otherwise -> self-operated
        if ($item->product->supplier_id) {
            return $item->product->supplier->provider_code ?? 'aliyun';
        }
        return 'proxmox'; // self-operated
    }
}
```

- [ ] **Step 5: Create OrderPaidListener**

```php
<?php
namespace App\Provisioning\Listener;

use App\Payment\Event\OrderPaid;
use App\Provisioning\Service\ProvisioningService;

class OrderPaidListener
{
    public function handle(OrderPaid $event): void
    {
        $service = new ProvisioningService();
        $service->handleOrderPaid($event->order);
    }
}
```

- [ ] **Step 6: Create ProvisionWorker (queue job)**

```php
<?php
namespace App\Provisioning\Queue;

use App\Provisioning\Model\ProvisionTask;
use App\Provisioning\Service\ProviderFactory;
use App\Provisioning\Model\Resource;
use App\Order\Model\Order;
use App\Order\Model\OrderTimeline;
use Webman\RedisQueue\Consumer;

class ProvisionWorker implements Consumer
{
    public string $queue = 'provisioning';

    public function consume($data)
    {
        $tasks = ProvisionTask::where('status', 'pending')
            ->where('next_retry_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('created_at')
            ->take(50)
            ->get();

        $factory = new ProviderFactory();

        foreach ($tasks as $task) {
            try {
                $provider = $factory->create($task);
                $result = $provider->create($task);

                if ($result->status === 'success') {
                    $task->update(['status' => 'completed']);

                    // Create resource record
                    Resource::create([
                        'order_item_id'  => $task->order_item_id,
                        'user_id'        => Order::find($task->order_id)->user_id,
                        'product_id'     => $task->orderItem->product_id,
                        'type'           => $task->product_type,
                        'provider'       => $task->provider,
                        'region_id'      => $task->region_id,
                        'status'         => 'active',
                        'provisioned_at' => date('Y-m-d H:i:s'),
                        'expired_at'     => $this->calcExpiry($task),
                    ]);

                    $this->checkOrderComplete($task->order_id);

                } elseif ($result->status === 'retryable') {
                    $retries = $task->retry_count + 1;
                    $delays  = [1, 5, 15, 60, 360, 86400]; // minutes
                    $delay   = $delays[$retries] ?? $delays[5];

                    $task->update([
                        'status'        => 'pending',
                        'retry_count'   => $retries,
                        'last_error'    => $result->errorMessage,
                        'next_retry_at' => date('Y-m-d H:i:s', time() + $delay * 60),
                    ]);

                    if ($retries >= 6) {
                        $task->update(['status' => 'failed']);
                        $this->alertFailure($task);
                    }
                } else {
                    $task->update([
                        'status'     => 'failed',
                        'last_error' => $result->errorMessage,
                    ]);
                    $this->alertFailure($task);
                }
            } catch (\Exception $e) {
                $task->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
                $this->alertFailure($task);
            }
        }
    }

    private function checkOrderComplete(int $orderId): void
    {
        $order = Order::with('items.tasks')->find($orderId);
        $allDone = $order->items->every(function ($item) {
            return $item->tasks->firstWhere('action', 'create')?->status === 'completed';
        });

        if ($allDone) {
            $order->update(['status' => 'completed']);
            OrderTimeline::create([
                'order_id' => $orderId,
                'status'   => 'completed',
                'operator' => 'system',
                'remark'   => 'All resources provisioned',
            ]);
        }
    }

    private function calcExpiry(ProvisionTask $task): string
    {
        $params = json_decode($task->params, true);
        $cycle  = $params['cycle'] ?? 'monthly';
        $months = $cycle === 'yearly' ? 12 : ($cycle === 'quarterly' ? 3 : 1);
        return date('Y-m-d H:i:s', strtotime("+{$months} months"));
    }

    private function alertFailure(ProvisionTask $task): void
    {
        // Trigger monitoring alert and auto-create ticket
        event(new ProvisionFailed($task));
    }
}
```

- [ ] **Step 7: Commit**

```bash
git add service/app/provisioning/
git commit -m "feat: implement provisioning engine core (Provider interface, factory, worker)"
```

---

### Task 2.2: Proxmox Provider implementation

**Files:**
- Create: `service/app/provisioning/provider/ProxmoxApi.php`
- Create: `service/app/provisioning/provider/ProxmoxProvider.php`
- Create: `service/app/provisioning/provider/HostSelector.php`
- Create: `service/app/provisioning/model/HostMachine.php`
- Create: `service/app/provisioning/model/IpPool.php`

- [ ] **Step 1: Create ProxmoxApi HTTP client**

```php
<?php
namespace App\Provisioning\Provider;

use GuzzleHttp\Client;
use App\Provisioning\Model\HostMachine;

class ProxmoxApi
{
    private string $baseUrl;
    private string $token;
    private Client $http;

    public function __construct(HostMachine $host)
    {
        $this->baseUrl = "https://{$host->ip_address}:8006/api2/json";
        $this->token   = decrypt($host->api_token_encrypted);
        $this->http    = new Client([
            'verify'  => false, // internal network
            'timeout' => 30,
        ]);
    }

    public function get(string $path, array $params = []): array
    {
        $response = $this->http->get($this->baseUrl . $path, [
            'headers' => ['Authorization' => "PVEAPIToken={$this->token}"],
            'query'   => $params,
        ]);
        return json_decode($response->getBody(), true)['data'];
    }

    public function post(string $path, array $data = []): array
    {
        $response = $this->http->post($this->baseUrl . $path, [
            'headers'     => ['Authorization' => "PVEAPIToken={$this->token}"],
            'form_params' => $data,
        ]);
        return json_decode($response->getBody(), true)['data'];
    }

    public function put(string $path, array $data = []): array
    {
        $response = $this->http->put($this->baseUrl . $path, [
            'headers'     => ['Authorization' => "PVEAPIToken={$this->token}"],
            'form_params' => $data,
        ]);
        return json_decode($response->getBody(), true)['data'];
    }

    public function delete(string $path): array
    {
        $response = $this->http->delete($this->baseUrl . $path, [
            'headers' => ['Authorization' => "PVEAPIToken={$this->token}"],
        ]);
        return json_decode($response->getBody(), true)['data'];
    }

    // Get next available VMID from Proxmox cluster
    public function nextVmid(): int
    {
        $result = $this->get('/cluster/nextid');
        return $result;
    }
}
```

- [ ] **Step 2: Create HostSelector**

```php
<?php
namespace App\Provisioning\Provider;

use App\Provisioning\Model\HostMachine;

class HostSelector
{
    public function select(int $regionId, array $specs): HostMachine
    {
        return HostMachine::where('region_id', $regionId)
            ->where('status', 'online')
            ->whereRaw("JSON_EXTRACT(specs, '$.cpu_total') - JSON_EXTRACT(specs, '$.cpu_allocated') >= ?", [$specs['cpu'] ?? 1])
            ->whereRaw("JSON_EXTRACT(specs, '$.ram_total_gb') - JSON_EXTRACT(specs, '$.ram_allocated_gb') >= ?", [$specs['ram'] ?? 1])
            ->whereRaw("JSON_EXTRACT(specs, '$.disk_total_gb') - JSON_EXTRACT(specs, '$.disk_allocated_gb') >= ?", [$specs['system_disk'] ?? 10])
            ->orderByRaw("JSON_EXTRACT(specs, '$.cpu_allocated') / NULLIF(JSON_EXTRACT(specs, '$.cpu_total'), 0) ASC")
            ->firstOrFail();
    }
}
```

- [ ] **Step 3: Create ProxmoxProvider**

```php
<?php
namespace App\Provisioning\Provider;

use App\Provisioning\Service\ProviderInterface;
use App\Provisioning\Model\ProvisionTask;
use App\Provisioning\Model\ProvisionResult;
use App\Provisioning\Model\Resource;
use App\Provisioning\Model\HostMachine;
use App\Provisioning\Model\IpPool;
use App\Provisioning\Model\IpAllocation;
use App\Provisioning\Model\Disk;

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
        $specs  = $params['specs'];

        try {
            // 1. Select host
            $host = $this->selector->select($task->region_id, $specs);

            // 2. Allocate IP
            $ip = $this->allocateIp($host->id);

            // 3. Create VM on Proxmox
            $api   = new ProxmoxApi($host);
            $vmid  = $api->nextVmid();
            $vmCfg = [
                'vmid'     => $vmid,
                'name'     => "vm-{$task->order_id}-{$task->order_item_id}",
                'cores'    => $specs['cpu'] ?? 2,
                'memory'   => ($specs['ram'] ?? 2) * 1024, // GB -> MB
                'net0'     => "virtio,bridge=vmbr0",
                'ipconfig0'=> "ip={$ip->address},gw={$ip->gateway}",
                'ostype'   => 'l26',
            ];

            $api->post("/nodes/{$host->proxmox_node}/qemu", $vmCfg);

            // 4. Create system disk
            $diskSize = $specs['system_disk'] ?? 20;
            $api->post("/nodes/{$host->proxmox_node}/qemu/{$vmid}/config", [
                'scsi0' => "{$host->storage_pool}:{$diskSize}G",
            ]);

            // 5. Start VM
            $api->post("/nodes/{$host->proxmox_node}/qemu/{$vmid}/status/start");

            // 6. Update host allocated resources
            $this->incrementHostAllocation($host, $specs, $diskSize);

            // 7. Create disk record
            $resource = Resource::where('order_item_id', $task->order_item_id)->first();
            Disk::create([
                'resource_id'   => $resource->id,
                'host_machine_id' => $host->id,
                'vm_id'         => $vmid,
                'size_gb'       => $diskSize,
                'disk_type'     => 'system',
                'storage_pool'  => $host->storage_pool,
                'device_path'   => 'scsi0',
                'status'        => 'active',
            ]);

            return ProvisionResult::success([
                'vmid'       => $vmid,
                'host_id'    => $host->id,
                'ip_address' => $ip->address,
            ]);

        } catch (\Exception $e) {
            // Network errors or Proxmox API errors — retryable
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
                // Update host allocation
                $hostSpecs = json_decode($host->specs, true);
                $oldCpu = $hostSpecs['cpu_allocated'] - ($resource->specs['cpu'] ?? 1);
                $hostSpecs['cpu_allocated'] = $oldCpu + $newSpecs['cpu'];
                $host->specs = json_encode($hostSpecs);
                $host->save();
            }

            if (isset($newSpecs['ram'])) {
                $api->put("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}/config", [
                    'memory' => $newSpecs['ram'] * 1024,
                ]);
                $hostSpecs = json_decode($host->specs, true);
                $oldRam = $hostSpecs['ram_allocated_gb'] - ($resource->specs['ram'] ?? 2);
                $hostSpecs['ram_allocated_gb'] = $oldRam + $newSpecs['ram'];
                $host->specs = json_encode($hostSpecs);
                $host->save();
            }

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
                'disk' => $disk->device_path,  // scsi0
                'size' => "{$newSizeGb}G",
            ]);

            // Record resize
            \App\Provisioning\Model\DiskResize::create([
                'disk_id'     => $disk->id,
                'old_size_gb' => $disk->size_gb,
                'new_size_gb' => $newSizeGb,
                'status'      => 'completed',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            $disk->update(['size_gb' => $newSizeGb]);

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

            // Find next available disk slot
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
                'resource_id'    => $task->resource_id,
                'host_machine_id'=> $host->id,
                'vm_id'          => $disk->vm_id,
                'size_gb'        => $params['size_gb'],
                'disk_type'      => 'data',
                'storage_pool'   => $host->storage_pool,
                'device_path'    => $devicePath,
                'status'         => 'active',
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

            // Add secondary network interface
            $existingNets = $api->get("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}/config");
            $netCount = 1;
            foreach ($existingNets as $key => $val) {
                if (str_starts_with($key, 'net')) {
                    $netCount++;
                }
            }

            $api->post("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}/config", [
                "net{$netCount}" => "virtio,bridge=vmbr0",
                "ipconfig{$netCount}" => "ip={$ip->address}",
            ]);

            return ProvisionResult::success(['ip' => $ip->address]);
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

            // Stop + delete VM
            $api->post("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}/status/stop");
            sleep(5);
            $api->delete("/nodes/{$host->proxmox_node}/qemu/{$disk->vm_id}");

            // Release IP(s)
            IpAllocation::where('resource_id', $resource->id)->update(['released_at' => date('Y-m-d H:i:s')]);

            // Decrement host allocation
            $hostSpecs = json_decode($host->specs, true);
            $hostSpecs['cpu_allocated'] -= ($resource->specs['cpu'] ?? 1);
            $hostSpecs['ram_allocated_gb'] -= ($resource->specs['ram'] ?? 2);
            // Deduct disk
            $totalDisk = Disk::where('host_machine_id', $host->id)
                ->where('vm_id', $disk->vm_id)
                ->sum('size_gb');
            $hostSpecs['disk_allocated_gb'] -= $totalDisk;
            $host->specs = json_encode($hostSpecs);
            $host->save();

            Disk::where('host_machine_id', $host->id)
                ->where('vm_id', $disk->vm_id)
                ->update(['status' => 'destroyed']);

            return ProvisionResult::success();
        } catch (\Exception $e) {
            return ProvisionResult::retryable($e->getMessage());
        }
    }

    public function renew(Resource $resource, int $months): ProvisionResult
    {
        // Proxmox VM doesn't need API action for renew — just extend expired_at
        $resource->update([
            'expired_at' => date('Y-m-d H:i:s', strtotime("+{$months} months", strtotime($resource->expired_at))),
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
            $status->status = $vmStatus['status'] ?? 'unknown'; // running/stopped
            $status->metrics = [
                'cpu_percent' => $vmStatus['cpu'] ?? 0,
                'mem_percent' => ($vmStatus['mem'] ?? 0) / ($vmStatus['maxmem'] ?? 1) * 100,
                'disk_percent'=> $vmStatus['disk'] ?? 0,
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
        return \Illuminate\Database\Capsule\Manager::transaction(function () use ($hostMachineId) {
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

        // Simple sequential allocation from pool range
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

    private function incrementHostAllocation(HostMachine $host, array $specs, int $diskGb): void
    {
        $h = json_decode($host->specs, true);
        $h['cpu_allocated']  += ($specs['cpu'] ?? 1);
        $h['ram_allocated_gb'] += ($specs['ram'] ?? 2);
        $h['disk_allocated_gb'] += $diskGb;
        $host->specs = json_encode($h);
        $host->save();
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add service/app/provisioning/provider/
git commit -m "feat: implement ProxmoxProvider with full lifecycle (create/upgrade/resize/disk/ip/destroy)"
```

---

### Task 2.3: Domain service

**Files:**
- Create: `service/app/domain/controller/DomainController.php`
- Create: `service/app/domain/service/DomainService.php`
- Create: `service/app/domain/model/DomainTld.php`
- Create: `service/app/domain/model/DnsZone.php`
- Create: `service/app/domain/model/DnsRecord.php`

- [ ] **Step 1: Create DomainService (key methods)**

```php
<?php
namespace App\Domain\Service;

use App\Domain\Model\DomainTld;

class DomainService
{
    // Check domain availability
    public function checkAvailability(string $domainName, string $tld): array
    {
        $tldConfig = DomainTld::where('tld', $tld)->firstOrFail();
        $api = $this->getRegistrarApi($tldConfig->registrar);
        
        $available = $api->checkDomain($domainName . '.' . $tld);
        
        return [
            'domain'    => $domainName,
            'tld'       => $tld,
            'available' => $available,
            'price'     => [
                'register' => $tldConfig->retail_price,
                'renew'    => $tldConfig->retail_price,
                'transfer' => $tldConfig->retail_price,
            ],
            'promo_price'     => $tldConfig->promo_price,
            'promo_price_end' => $tldConfig->promo_end_at,
        ];
    }

    // Register domain
    public function register(int $userId, string $domainName, string $tld, array $options = []): void
    {
        $tldConfig = DomainTld::where('tld', $tld)->firstOrFail();

        // Create registration task via provisioning engine
        ProvisionTask::create([
            'order_id'      => $options['order_id'] ?? 0,
            'order_item_id' => $options['order_item_id'] ?? 0,
            'product_type'  => 'domain',
            'provider'      => $tldConfig->registrar,
            'action'        => 'register',
            'status'        => 'pending',
            'params'        => json_encode([
                'domain'       => $domainName . '.' . $tld,
                'years'        => $options['years'] ?? 1,
                'whois_privacy'=> $options['whois_privacy'] ?? true,
                'nameservers'  => $options['nameservers'] ?? [],
            ]),
            'next_retry_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // DNS record management
    public function addDnsRecord(int $userId, string $domainName, array $data): DnsRecord
    {
        $zone = DnsZone::where('domain_name', $domainName)
            ->where('user_id', $userId)
            ->firstOrFail();

        return DnsRecord::create([
            'zone_id'  => $zone->id,
            'type'     => $data['type'],       // A/AAAA/CNAME/MX/TXT/NS
            'name'     => $data['name'],       // @ or subdomain
            'value'    => $data['value'],
            'ttl'      => $data['ttl'] ?? 600,
            'priority' => $data['priority'] ?? null,
        ]);
    }

    public function listDnsRecords(int $userId, string $domainName): array
    {
        $zone = DnsZone::where('domain_name', $domainName)
            ->where('user_id', $userId)
            ->firstOrFail();

        return DnsRecord::where('zone_id', $zone->id)->get()->toArray();
    }

    public function deleteDnsRecord(int $userId, string $domainName, int $recordId): void
    {
        $zone = DnsZone::where('domain_name', $domainName)
            ->where('user_id', $userId)
            ->firstOrFail();

        DnsRecord::where('id', $recordId)->where('zone_id', $zone->id)->delete();
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add service/app/domain/
git commit -m "feat: implement domain service (availability check, register, DNS management)"
```

---

### Task 2.4: Supplier system

**Files:**
- Create: `service/app/supplier/controller/SupplierController.php`
- Create: `service/app/supplier/service/SupplierService.php`
- Create: `service/app/supplier/model/Supplier.php`
- Create: `service/app/supplier/model/SupplierSettlement.php`

- [ ] **Step 1: Create SupplierService (key business logic)**

```php
<?php
namespace App\Supplier\Service;

use App\Supplier\Model\Supplier;
use App\Supplier\Model\SupplierSettlement;
use App\Supplier\Model\SupplierWithdraw;
use App\Order\Model\OrderItem;
use Illuminate\Database\Capsule\Manager as DB;

class SupplierService
{
    // Supplier application
    public function apply(int $userId, array $data): Supplier
    {
        if (Supplier::where('user_id', $userId)->exists()) {
            throw new \InvalidArgumentException('You already have a supplier application');
        }

        return Supplier::create([
            'user_id'          => $userId,
            'company_name'     => $data['company_name'],
            'contact_name'     => $data['contact_name'],
            'contact_phone'    => $data['contact_phone'],
            'contact_email'    => $data['contact_email'],
            'status'           => 'pending',
            'settlement_method'=> $data['settlement_method'] ?? 'bank',
        ]);
    }

    // Admin approves
    public function approve(int $supplierId, int $adminId): void
    {
        $supplier = Supplier::findOrFail($supplierId);
        $supplier->update([
            'status'      => 'active',
            'approved_by' => $adminId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        // Upgrade user role to supplier
        \App\User\Model\User::where('id', $supplier->user_id)->update(['role' => 'supplier']);
    }

    // Calculate and generate settlement
    public function generateSettlement(int $supplierId, string $periodStart, string $periodEnd): SupplierSettlement
    {
        $items = OrderItem::whereHas('product', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId);
            })
            ->whereHas('order', function ($q) use ($periodStart, $periodEnd) {
                $q->where('status', 'completed')
                  ->whereBetween('paid_at', [$periodStart, $periodEnd]);
            })
            ->get();

        $totalSales   = $items->sum('total_price');
        $commission   = $items->sum(function ($item) {
            $rate = $item->product->supplierProduct->commission_rate ?? 0.10;
            return bcmul($item->total_price, $rate, 4);
        });
        $payable = bcsub($totalSales, $commission, 4);

        return SupplierSettlement::create([
            'supplier_id'  => $supplierId,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'total_sales'  => $totalSales,
            'commission'   => $commission,
            'payable'      => $payable,
            'status'       => 'pending',
        ]);
    }

    // Supplier requests withdrawal
    public function requestWithdraw(int $supplierId, string $amount, array $accountInfo): void
    {
        $available = SupplierSettlement::where('supplier_id', $supplierId)
            ->where('status', 'completed')
            ->sum('payable');

        $pending = SupplierWithdraw::where('supplier_id', $supplierId)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        $withdrawable = bcsub($available, $pending, 4);

        if (bccomp($amount, $withdrawable, 4) > 0) {
            throw new \InvalidArgumentException('Insufficient withdrawable balance');
        }

        SupplierWithdraw::create([
            'supplier_id'  => $supplierId,
            'amount'       => $amount,
            'method'       => $accountInfo['method'],
            'account_info' => json_encode($accountInfo),
            'status'       => 'pending',
        ]);
    }

    // Global scope for supplier data isolation
    public static function applySupplierScope($query): void
    {
        $user = auth()->user();
        if ($user && $user->role === 'supplier') {
            $supplier = Supplier::where('user_id', $user->id)->first();
            if ($supplier) {
                $query->where('supplier_id', $supplier->id);
            }
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add service/app/supplier/
git commit -m "feat: implement supplier system (apply, approve, settlement, withdraw)"
```

---

### Task 2.5: Wire Phase 2 routes and register queue

- [ ] **Step 1: Update route config**

```php
// Provisioning routes (admin only)
Route::group('/admin/api/v1/provisioning', function () {
    Route::get('/tasks', [\App\Provisioning\Controller\TaskController::class, 'index']);
    Route::post('/tasks/{id}/retry', [\App\Provisioning\Controller\TaskController::class, 'retry']);
    Route::post('/resources/{id}/upgrade', [\App\Provisioning\Controller\ResourceController::class, 'upgrade']);
    Route::post('/resources/{id}/destroy', [\App\Provisioning\Controller\ResourceController::class, 'destroy']);
    Route::get('/hosts', [\App\Provisioning\Controller\HostController::class, 'index']);
})->middleware([AuthMiddleware::class, RbacMiddleware::class . ':resource.view']);

// User resource routes
Route::group('/api/v1/user/resources', function () {
    Route::get('', [\App\Provisioning\Controller\ResourceController::class, 'myResources']);
    Route::get('/{id}', [\App\Provisioning\Controller\ResourceController::class, 'show']);
    Route::get('/{id}/status', [\App\Provisioning\Controller\ResourceController::class, 'status']);
    Route::get('/{id}/console', [\App\Provisioning\Controller\ResourceController::class, 'consoleUrl']);
})->middleware([AuthMiddleware::class]);

// Domain routes
Route::get('/api/v1/domain/check/{domain}/{tld}', [\App\Domain\Controller\DomainController::class, 'check']);
Route::get('/api/v1/domain/tlds', [\App\Domain\Controller\DomainController::class, 'tlds']);
Route::group('/api/v1/user/dns', function () {
    Route::get('/{domain}', [\App\Domain\Controller\DomainController::class, 'listRecords']);
    Route::post('/{domain}/records', [\App\Domain\Controller\DomainController::class, 'addRecord']);
    Route::delete('/{domain}/records/{id}', [\App\Domain\Controller\DomainController::class, 'deleteRecord']);
})->middleware([AuthMiddleware::class]);

// Supplier routes
Route::post('/api/v1/supplier/apply', [\App\Supplier\Controller\SupplierController::class, 'apply'])
    ->middleware([AuthMiddleware::class]);
Route::get('/api/v1/supplier/settlements', [\App\Supplier\Controller\SupplierController::class, 'settlements'])
    ->middleware([AuthMiddleware::class]);
```

- [ ] **Step 2: Register ProvisionWorker in queue config**

```php
// config/plugin/webman/redis-queue/process.php
return [
    'provisioning' => [
        'handler' => \App\Provisioning\Queue\ProvisionWorker::class,
        'count'   => 2, // 2 consumer processes
    ],
];
```

- [ ] **Step 3: Commit**

```bash
git add service/config/
git commit -m "feat: wire Phase 2 routes and register provisioning queue worker"
```

---

**Phase 2 complete.** Payment now triggers automatic resource provisioning via Proxmox (or third-party cloud APIs). Users can manage their resources, domains, and DNS. Suppliers can onboard, list products, and receive settlements.
