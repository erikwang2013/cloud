<?php
namespace App\payment\event;

use App\order\model\Order;

class OrderPaid
{
    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }
}
