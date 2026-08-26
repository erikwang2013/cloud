<?php
namespace App\provisioning\listener;

use App\payment\event\OrderPaid;
use App\provisioning\service\ProvisioningService;

class OrderPaidListener
{
    public function handle(OrderPaid $event): void
    {
        $service = new ProvisioningService();
        $service->handleOrderPaid($event->order);
    }
}
