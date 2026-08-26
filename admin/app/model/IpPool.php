<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class IpPool extends Base
{
    protected $table = 'ip_pools';
    protected $fillable = ['host_machine_id', 'ip_start', 'ip_end', 'gateway', 'total_count', 'used_count'];
}
