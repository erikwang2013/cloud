<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class AuditLog extends Base
{
    protected $table = 'audit_logs';
    protected $fillable = ['user_id', 'action', 'resource_type', 'resource_id', 'ip_address', 'user_agent', 'old_values', 'new_values'];
}
