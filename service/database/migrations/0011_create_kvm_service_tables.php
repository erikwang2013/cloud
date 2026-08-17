<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// host_machines：hypervisor 标识（proxmox/kvm），KVM 宿主存 libvirt URI
Capsule::schema()->table('host_machines', function (Blueprint $table) {
    if (!Capsule::schema()->hasColumn('host_machines', 'hypervisor')) {
        $table->string('hypervisor', 16)->default('proxmox')->after('api_token_encrypted');
    }
    if (!Capsule::schema()->hasColumn('host_machines', 'kvm_connection')) {
        $table->string('kvm_connection', 255)->nullable()->after('hypervisor');
    }
});

// network_services：每 VM 私有 bridge + 子网（隔离单元）
if (!Capsule::schema()->hasTable('network_services')) {
    Capsule::schema()->create('network_services', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('host_machine_id');
        $table->unsignedBigInteger('resource_id');
        $table->string('vm_id', 128);
        $table->string('bridge_name', 64);
        $table->string('subnet', 45)->nullable();
        $table->string('gateway_ip', 45)->nullable();
        $table->string('status', 20)->default('creating');
        $table->timestamps();
        $table->index(['host_machine_id', 'resource_id']);
        $table->unique(['host_machine_id', 'bridge_name']);
    });
}

// firewall_services：每 VM 独立 nftables 表（隔离单元）
if (!Capsule::schema()->hasTable('firewall_services')) {
    Capsule::schema()->create('firewall_services', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('host_machine_id');
        $table->unsignedBigInteger('resource_id');
        $table->string('vm_id', 128);
        $table->string('table_name', 64);
        $table->string('default_policy', 16)->default('drop');
        $table->json('rules')->nullable();
        $table->string('status', 20)->default('creating');
        $table->timestamps();
        $table->index(['host_machine_id', 'resource_id']);
        $table->unique(['host_machine_id', 'table_name']);
    });
}

// switch_services：veth pair 把 VM 网卡接到网络服务（绑定单元）
if (!Capsule::schema()->hasTable('switch_services')) {
    Capsule::schema()->create('switch_services', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('host_machine_id');
        $table->unsignedBigInteger('resource_id');
        $table->string('vm_id', 128);
        $table->unsignedBigInteger('network_service_id');
        $table->string('veth_host', 64);
        $table->string('veth_guest', 64);
        $table->string('mac_address', 32)->nullable();
        $table->string('status', 20)->default('creating');
        $table->timestamps();
        $table->index(['host_machine_id', 'resource_id']);
        $table->unique(['host_machine_id', 'veth_host']);
    });
}

echo "Added KVM service tables and host_machines columns.\n";
