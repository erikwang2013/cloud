<?php
namespace App\WebSocket\Listener;

use App\Ticket\Event\TicketStatusChanged;
use App\WebSocket\WebSocketServer;

class TicketUpdatedListener
{
    public function handle(TicketStatusChanged $event): void
    {
        WebSocketServer::send($event->ticket->user_id, 'ticket.updated', [
            'ticket_id' => $event->ticket->id,
            'title'     => $event->ticket->title,
            'status'    => $event->ticket->status,
        ]);
    }
}
