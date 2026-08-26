<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class DnsRecord extends Base
{
    protected $table = 'dns_records';
    protected $fillable = ['zone_id', 'type', 'name', 'value', 'ttl', 'priority'];
}
