<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

class TicketMessage extends Base
{
    protected $table = 'ticket_messages';
    protected $fillable = ['ticket_id', 'sender_id', 'sender_type', 'content'];
}
