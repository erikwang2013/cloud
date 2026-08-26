<?php
namespace App\ticket\event;

use App\ticket\model\Ticket;

class TicketCreated
{
    public Ticket $ticket;

    public function __construct(Ticket $ticket)
    {
        $this->ticket = $ticket;
    }
}
