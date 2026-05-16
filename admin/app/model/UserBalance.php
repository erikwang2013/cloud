<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class UserBalance extends Base
{
    protected $table = 'erik_user_balances';
    protected $fillable = ['user_id', 'currency', 'balance', 'frozen_balance'];
}
