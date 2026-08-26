<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class PaymentTransaction extends Base
{
    protected $table = 'payment_transactions';
    protected $fillable = ['order_id', 'user_id', 'channel_id', 'amount', 'currency', 'exchange_rate', 'channel_fee', 'transaction_no', 'status', 'callback_at'];
}
