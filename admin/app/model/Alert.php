<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class Alert extends Base
{
    protected $table = 'alerts';
    protected $fillable = ['rule_code', 'severity', 'resource_id', 'user_id', 'context', 'status'];
}
