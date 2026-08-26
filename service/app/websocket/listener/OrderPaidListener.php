<?php
namespace App\websocket\listener;

use App\payment\event\OrderPaid;
use App\websocket\WebSocketServer;

class OrderPaidListener
{
    public function handle(OrderPaid $event): void
    {
        WebSocketServer::send($event->order->user_id, 'order.paid', [
            'order_id' => $event->order->id,
            'order_no' => $event->order->order_no,
            'amount'   => $event->order->total,
            'currency' => $event->order->currency,
        ]);
    }
}
