<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class Order extends Base
{
    protected $table = 'orders';
    protected $fillable = ['order_no', 'user_id', 'type', 'status', 'currency', 'subtotal', 'discount', 'tax', 'total', 'exchange_rate', 'paid_at'];
}
