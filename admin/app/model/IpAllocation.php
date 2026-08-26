<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class IpAllocation extends Base
{
    protected $table = 'ip_allocations';
    protected $fillable = ['ip_pool_id', 'resource_id', 'ip_address', 'type', 'allocated_at', 'released_at'];
}
