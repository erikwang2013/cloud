<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class SupplierSettlement extends Base
{
    protected $table = 'supplier_settlements';
    protected $fillable = ['supplier_id', 'period_start', 'period_end', 'total_sales', 'commission', 'payable', 'status'];
}
