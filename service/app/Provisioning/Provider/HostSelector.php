<?php
namespace App\Provisioning\Provider;

use App\Provisioning\Model\HostMachine;

class HostSelector
{
    public function select(int $regionId, array $specs, ?string $hypervisor = null): HostMachine
    {
        return HostMachine::where('region_id', $regionId)
            ->when($hypervisor, fn($q) => $q->where('hypervisor', $hypervisor))
            ->where('status', 'online')
            ->whereRaw("JSON_EXTRACT(specs, '$.cpu_total') - JSON_EXTRACT(specs, '$.cpu_allocated') >= ?", [$specs['cpu'] ?? 1])
            ->whereRaw("JSON_EXTRACT(specs, '$.ram_total_gb') - JSON_EXTRACT(specs, '$.ram_allocated_gb') >= ?", [$specs['ram'] ?? 1])
            ->whereRaw("JSON_EXTRACT(specs, '$.disk_total_gb') - JSON_EXTRACT(specs, '$.disk_allocated_gb') >= ?", [$specs['system_disk'] ?? 10])
            ->orderByRaw("JSON_EXTRACT(specs, '$.cpu_allocated') / NULLIF(JSON_EXTRACT(specs, '$.cpu_total'), 0) ASC")
            ->firstOrFail();
    }
}
