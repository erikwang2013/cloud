<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class SupplierWithdraw extends Base
{
    protected $table = 'supplier_withdraws';
    protected $fillable = ['supplier_id', 'amount', 'method', 'account_info', 'status'];
}
