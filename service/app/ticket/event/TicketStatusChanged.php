<?php
namespace App\ticket\event;

use App\ticket\model\Ticket;

class TicketStatusChanged
{
    public Ticket $ticket;

    public function __construct(Ticket $ticket)
    {
        $this->ticket = $ticket;
    }
}
