<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class ProductImage extends Base
{
    protected $table = 'product_images';
    protected $fillable = ['product_id', 'url', 'sort'];
}
