<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// host_machines
if (!Capsule::schema()->hasTable('host_machines')) {
    Capsule::schema()->create('host_machines', function (Blueprint $table) {
        $table->id();
        $table->string('name', 100);
        $table->unsignedBigInteger('region_id');
        $table->string('ip_address', 45);
        $table->integer('ssh_port')->default(22);
        $table->string('proxmox_node', 100)->nullable();
        $table->string('proxmox_api_url', 255)->nullable();
        $table->string('api_token_encrypted', 500)->nullable();
        $table->string('status', 20)->default('online');
        $table->json('specs')->nullable();
        $table->string('storage_pool', 100)->nullable();
        $table->timestamps();
    });
}


// ip_pools
if (!Capsule::schema()->hasTable('ip_pools')) {
    Capsule::schema()->create('ip_pools', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('host_machine_id');
        $table->unsignedBigInteger('region_id');
        $table->string('network_cidr', 45);
        $table->string('gateway', 45)->nullable();
        $table->integer('vlan_id')->nullable();
        $table->string('ip_start', 45);
        $table->string('ip_end', 45);
        $table->integer('total_count')->default(0);
        $table->integer('used_count')->default(0);
        $table->string('status', 20)->default('active');
        $table->timestamps();
    });
}


// ip_allocations
if (!Capsule::schema()->hasTable('ip_allocations')) {
    Capsule::schema()->create('ip_allocations', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('ip_pool_id');
        $table->unsignedBigInteger('resource_id')->nullable();
        $table->string('ip_address', 45);
        $table->string('type', 20)->default('primary');
        $table->timestamp('allocated_at')->nullable();
        $table->timestamp('released_at')->nullable();
        $table->timestamps();
    });
}


// disks
if (!Capsule::schema()->hasTable('disks')) {
    Capsule::schema()->create('disks', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('resource_id')->nullable();
        $table->unsignedBigInteger('host_machine_id');
        $table->unsignedBigInteger('vm_id')->nullable();
        $table->integer('size_gb');
        $table->string('disk_type', 20)->default('system');
        $table->string('storage_pool', 100)->nullable();
        $table->string('device_path', 255)->nullable();
        $table->string('status', 20)->default('active');
        $table->timestamps();
    });
}


// disk_resizes
if (!Capsule::schema()->hasTable('disk_resizes')) {
    Capsule::schema()->create('disk_resizes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('disk_id');
        $table->integer('old_size_gb');
        $table->integer('new_size_gb');
        $table->string('status', 20)->default('pending');
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();
    });
}


echo "Host tables created.\n";
