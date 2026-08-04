<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class ProductCategory extends Base
{
    protected $table = 'erik_product_categories';
    protected $fillable = ['parent_id', 'name', 'slug', 'type', 'sort', 'icon', 'status'];
}
