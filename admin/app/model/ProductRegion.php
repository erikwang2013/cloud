<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class ProductRegion extends Base
{
    protected $table = 'erik_product_regions';
    protected $fillable = ['sku_id', 'region_id', 'price', 'original_price', 'stock', 'currency'];
}
