<?php
namespace App\Provisioning\Provider\Kvm;

/**
 * KVM 宿主驱动：真机实现（VirshDriver）与模拟实现（SimulatedKvmDriver）共用。
 * Phase 2 在 VirshDriver 落地真实命令后，模拟驱动保留供测试。
 */
interface KvmDriverInterface
{
    /** 创建 VM，返回 vmId */
    public function createVm(array $spec): string;

    /** 建每 VM 私有 bridge（隔离单元） */
    public function createBridge(string $bridgeName): void;

    /** 建 veth pair 并把宿主端接入 bridge */
    public function createVeth(string $hostName, string $guestName, string $bridgeName, string $macAddress): void;

    /** 挂载 qcow2 磁盘 */
    public function attachDisk(string $vmId, string $devicePath, int $sizeGb): void;

    public function startVm(string $vmId): void;

    /** 应用 per-VM nftables 表（rules 见 FirewallService.rules JSON） */
    public function applyFirewall(string $tableName, string $defaultPolicy, array $rules): void;

    public function destroyVm(string $vmId): void;

    public function removeBridge(string $bridgeName): void;

    public function removeVeth(string $hostName): void;

    public function removeFirewall(string $tableName): void;

    /** running/stopped/pending/error */
    public function status(string $vmId): string;
}
