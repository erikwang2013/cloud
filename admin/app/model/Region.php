<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class Region extends Base
{
    protected $table = 'regions';
    protected $fillable = ['name', 'continent', 'country', 'city', 'data_center', 'status'];
}
