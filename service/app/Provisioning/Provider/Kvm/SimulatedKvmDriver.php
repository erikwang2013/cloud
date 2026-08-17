<?php
namespace App\Provisioning\Provider\Kvm;

/**
 * 内存态模拟驱动：测试与本地全流程验证用，不做任何真实宿主操作。
 */
class SimulatedKvmDriver implements KvmDriverInterface
{
    public array $calls   = [];
    public array $bridges = [];
    public array $veths   = [];
    public array $vms     = [];
    public array $tables  = [];

    public function createVm(array $spec): string
    {
        // spec['vmId'] 为确定性逻辑 id（kvm-{resource_id}），记录与驱动状态保持一致
        $vmId = $spec['vmId'] ?? ('kvm-sim-' . (count($this->vms) + 1));
        $this->vms[$vmId] = ['spec' => $spec, 'status' => 'stopped'];
        $this->calls[] = ['createVm', $spec];
        return $vmId;
    }

    public function createBridge(string $bridgeName): void
    {
        $this->bridges[$bridgeName] = [];
        $this->calls[] = ['createBridge', $bridgeName];
    }

    public function createVeth(string $hostName, string $guestName, string $bridgeName, string $macAddress): void
    {
        $this->veths[$hostName] = ['guest' => $guestName, 'bridge' => $bridgeName, 'mac' => $macAddress];
        $this->calls[] = ['createVeth', $hostName, $guestName, $bridgeName, $macAddress];
    }

    public function attachDisk(string $vmId, string $devicePath, int $sizeGb): void
    {
        $this->calls[] = ['attachDisk', $vmId, $devicePath, $sizeGb];
    }

    public function startVm(string $vmId): void
    {
        if (isset($this->vms[$vmId])) {
            $this->vms[$vmId]['status'] = 'running';
        }
        $this->calls[] = ['startVm', $vmId];
    }

    public function applyFirewall(string $tableName, string $defaultPolicy, array $rules): void
    {
        $this->tables[$tableName] = ['policy' => $defaultPolicy, 'rules' => $rules];
        $this->calls[] = ['applyFirewall', $tableName, $defaultPolicy, $rules];
    }

    public function destroyVm(string $vmId): void
    {
        if (isset($this->vms[$vmId])) {
            $this->vms[$vmId]['status'] = 'destroyed';
        }
        $this->calls[] = ['destroyVm', $vmId];
    }

    public function removeBridge(string $bridgeName): void
    {
        unset($this->bridges[$bridgeName]);
        $this->calls[] = ['removeBridge', $bridgeName];
    }

    public function removeVeth(string $hostName): void
    {
        unset($this->veths[$hostName]);
        $this->calls[] = ['removeVeth', $hostName];
    }

    public function removeFirewall(string $tableName): void
    {
        unset($this->tables[$tableName]);
        $this->calls[] = ['removeFirewall', $tableName];
    }

    public function status(string $vmId): string
    {
        return $this->vms[$vmId]['status'] ?? 'error';
    }
}
