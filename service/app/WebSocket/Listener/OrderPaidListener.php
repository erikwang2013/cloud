<?php
namespace App\WebSocket\Listener;

use App\Payment\Event\OrderPaid;
use App\WebSocket\WebSocketServer;

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
