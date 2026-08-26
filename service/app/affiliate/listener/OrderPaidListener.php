<?php
namespace App\affiliate\listener;

use App\payment\event\OrderPaid;
use App\affiliate\service\AffiliateService;

class OrderPaidListener
{
    public function __invoke(OrderPaid $event): void
    {
        $order = $event->order;
        try {
            (new AffiliateService())->attributeOrder($order->id, $order->user_id);
        } catch (\Throwable $e) {
            echo "Affiliate attribution error: {$e->getMessage()}\n";
        }
    }
}
