<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class DiskResize extends Base
{
    protected $table = 'disk_resizes';
    protected $fillable = ['disk_id', 'old_size_gb', 'new_size_gb', 'status', 'finished_at'];
}
