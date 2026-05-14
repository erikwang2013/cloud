<?php
namespace App\Ticket\Listener;

use App\Ticket\Event\TicketCreated;
use App\Ticket\Service\TicketService;

class AutoAssignListener
{
    public function handle(TicketCreated $event): void
    {
        $service = new TicketService();
        $service->autoAssign($event->ticket);
    }
}
