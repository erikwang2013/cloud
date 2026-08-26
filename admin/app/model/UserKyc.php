<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class UserKyc extends Base
{
    protected $table = 'user_kyc';
    protected $fillable = ['user_id', 'id_type', 'id_number_encrypted', 'real_name', 'front_image', 'back_image', 'status', 'reject_reason', 'verified_at', 'verified_by'];
}
