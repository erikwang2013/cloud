<?php
namespace App\Provisioning\Provider\Kvm;

use App\Provisioning\Model\HostMachine;

/**
 * libvirt (virsh) 真机驱动骨架：Phase 2 落地真实命令（经 SSH 到宿主执行）。
 * 当前全部方法抛 NotImplemented，保证接口形状与编排层先行可用。
 */
class VirshDriver implements KvmDriverInterface
{
    private ?HostMachine $host = null;

    public function setHost(HostMachine $host): void
    {
        $this->host = $host;
    }

    public function createVm(array $spec): string
    {
        // TODO: virsh define <vm.xml>（spec: cpu/ram/disk/vmId/mac/bridge）
        throw new \RuntimeException('TODO: VirshDriver::createVm not implemented (Phase 2)');
    }

    public function createBridge(string $bridgeName): void
    {
        // TODO: ip link add {bridgeName} type bridge && ip addr add {subnet}/24 dev {bridgeName}
        throw new \RuntimeException('TODO: VirshDriver::createBridge not implemented (Phase 2)');
    }

    public function createVeth(string $hostName, string $guestName, string $bridgeName, string $macAddress): void
    {
        // TODO: ip link add {hostName} type veth peer name {guestName} && ip link set {hostName} master {bridgeName}
        throw new \RuntimeException('TODO: VirshDriver::createVeth not implemented (Phase 2)');
    }

    public function attachDisk(string $vmId, string $devicePath, int $sizeGb): void
    {
        // TODO: qemu-img create -f qcow2 {path} {sizeGb}G && virsh attach-disk {vmId}
        throw new \RuntimeException('TODO: VirshDriver::attachDisk not implemented (Phase 2)');
    }

    public function startVm(string $vmId): void
    {
        // TODO: virsh start {vmId}
        throw new \RuntimeException('TODO: VirshDriver::startVm not implemented (Phase 2)');
    }

    public function applyFirewall(string $tableName, string $defaultPolicy, array $rules): void
    {
        // TODO: nft add table inet {tableName} && nft add chain ... (per-VM 表 = 隔离)
        throw new \RuntimeException('TODO: VirshDriver::applyFirewall not implemented (Phase 2)');
    }

    public function destroyVm(string $vmId): void
    {
        // TODO: virsh destroy {vmId} && virsh undefine {vmId}
        throw new \RuntimeException('TODO: VirshDriver::destroyVm not implemented (Phase 2)');
    }

    public function removeBridge(string $bridgeName): void
    {
        // TODO: ip link del {bridgeName}
        throw new \RuntimeException('TODO: VirshDriver::removeBridge not implemented (Phase 2)');
    }

    public function removeVeth(string $hostName): void
    {
        // TODO: ip link del {hostName}
        throw new \RuntimeException('TODO: VirshDriver::removeVeth not implemented (Phase 2)');
    }

    public function removeFirewall(string $tableName): void
    {
        // TODO: nft delete table inet {tableName}
        throw new \RuntimeException('TODO: VirshDriver::removeFirewall not implemented (Phase 2)');
    }

    public function status(string $vmId): string
    {
        // TODO: virsh domstate {vmId}
        throw new \RuntimeException('TODO: VirshDriver::status not implemented (Phase 2)');
    }
}
