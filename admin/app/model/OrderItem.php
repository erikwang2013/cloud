<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class OrderItem extends Base
{
    protected $table = 'erik_order_items';
    protected $fillable = ['order_id', 'sku_id', 'region_id', 'product_id', 'quantity', 'cycle', 'unit_price', 'total_price', 'resource_snapshot', 'status'];
}
