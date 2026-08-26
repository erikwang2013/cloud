<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class Resource extends Base
{
    protected $table = 'resources';
    protected $fillable = ['order_item_id', 'user_id', 'product_id', 'type', 'provider', 'region_id', 'status', 'specs', 'provisioned_at', 'expired_at'];
}
