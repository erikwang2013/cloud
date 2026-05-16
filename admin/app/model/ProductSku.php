<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class ProductSku extends Base
{
    protected $table = 'erik_product_skus';
    protected $fillable = ['product_id', 'sku_code', 'specs', 'status'];
}
