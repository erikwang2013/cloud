<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class Disk extends Base
{
    protected $table = 'erik_disks';
    protected $fillable = ['resource_id', 'host_machine_id', 'vm_id', 'size_gb', 'disk_type', 'storage_pool', 'device_path', 'status'];
}
