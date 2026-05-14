<?php
namespace App\Payment\Event;

use App\Order\Model\Order;

class OrderPaid
{
    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }
}
