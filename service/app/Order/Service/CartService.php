<?php
namespace App\Order\Service;

use App\Order\Model\Cart;

class CartService
{
    public function getCart(int $userId): array
    {
        return Cart::where('user_id', $userId)
            ->with(['sku.product', 'sku.regionPrices.region'])
            ->get()
            ->toArray();
    }
}
