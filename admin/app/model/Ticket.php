<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class Ticket extends Base
{
    protected $table = 'tickets';
    protected $fillable = ['ticket_no', 'user_id', 'resource_id', 'category', 'priority', 'title', 'status', 'assigned_to', 'sla_deadline', 'closed_by', 'closed_at'];
}
