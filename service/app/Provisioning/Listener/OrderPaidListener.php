<?php
namespace App\Provisioning\Listener;

use App\Payment\Event\OrderPaid;
use App\Provisioning\Service\ProvisioningService;

class OrderPaidListener
{
    public function handle(OrderPaid $event): void
    {
        $service = new ProvisioningService();
        $service->handleOrderPaid($event->order);
    }
}
