<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class ProvisionTask extends Base
{
    protected $table = 'erik_provision_tasks';
    protected $fillable = ['order_id', 'order_item_id', 'resource_id', 'product_type', 'provider', 'region_id', 'action', 'status', 'params', 'retry_count', 'last_error', 'next_retry_at'];
}
