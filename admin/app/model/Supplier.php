<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class Supplier extends Base
{
    protected $table = 'suppliers';
    protected $fillable = ['user_id', 'company_name', 'contact_name', 'contact_phone', 'contact_email', 'status', 'settlement_method', 'approved_by', 'approved_at'];
}
