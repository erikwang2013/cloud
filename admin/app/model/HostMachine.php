<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class HostMachine extends Base
{
    protected $table = 'host_machines';
    protected $fillable = ['region_id', 'name', 'ip_address', 'proxmox_node', 'storage_pool', 'api_token_encrypted', 'specs', 'status'];
}
