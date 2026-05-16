<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class OrderTimeline extends Base
{
    protected $table = 'erik_order_timeline';
    protected $fillable = ['order_id', 'status', 'operator', 'remark'];
}
