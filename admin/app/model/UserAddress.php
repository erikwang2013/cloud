<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class UserAddress extends Base
{
    protected $table = 'erik_user_addresses';
    protected $fillable = ['user_id', 'type', 'name', 'phone', 'country', 'state', 'city', 'address', 'postcode', 'is_default'];
}
