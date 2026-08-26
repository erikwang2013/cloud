<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class Refund extends Base
{
    protected $table = 'refunds';
    protected $fillable = ['order_id', 'user_id', 'amount', 'reason', 'status'];
}
