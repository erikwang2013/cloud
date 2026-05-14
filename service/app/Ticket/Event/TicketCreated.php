<?php
namespace App\Ticket\Event;

use App\Ticket\Model\Ticket;

class TicketCreated
{
    public Ticket $ticket;

    public function __construct(Ticket $ticket)
    {
        $this->ticket = $ticket;
    }
}
