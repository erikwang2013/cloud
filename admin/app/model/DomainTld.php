<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class DomainTld extends Base
{
    protected $table = 'erik_domain_tlds';
    protected $fillable = ['tld', 'registrar', 'retail_price', 'promo_price', 'promo_end_at'];
}
