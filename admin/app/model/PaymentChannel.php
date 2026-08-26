<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class PaymentChannel extends Base
{
    protected $table = 'payment_channels';
    protected $fillable = ['name', 'code', 'api_key_encrypted', 'currency_support', 'fee_config', 'is_visible', 'visible_regions', 'min_amount', 'max_amount', 'webhook_secret', 'status'];
}
