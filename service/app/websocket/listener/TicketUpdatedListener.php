<?php
namespace App\websocket\listener;

use App\ticket\event\TicketStatusChanged;
use App\websocket\WebSocketServer;

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
