<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class ProductReview extends Base
{
    protected $table = 'product_reviews';
    protected $fillable = ['user_id', 'product_id', 'order_id', 'rating', 'content', 'status'];
}
