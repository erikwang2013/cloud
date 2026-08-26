<?php
namespace App\ticket\listener;

use App\ticket\event\TicketCreated;
use App\ticket\service\TicketService;

class AutoAssignListener
{
    public function handle(TicketCreated $event): void
    {
        $service = new TicketService();
        $service->autoAssign($event->ticket);
    }
}
