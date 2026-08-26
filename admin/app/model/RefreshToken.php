<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class RefreshToken extends Base
{
    protected $table = 'refresh_tokens';
    protected $fillable = ['user_id', 'token_hash', 'device_fingerprint', 'expires_at', 'revoked'];
}
