<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class DnsZone extends Base
{
    protected $table = 'erik_dns_zones';
    protected $fillable = ['user_id', 'domain_name'];
}
