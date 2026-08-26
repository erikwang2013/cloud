<?php
namespace App\order\service;

use App\order\model\Cart;

class CartService
{
    public function getCart(int $userId): array
    {
        return Cart::where('user_id', $userId)
            ->with(['sku.product', 'sku.regionPrices.region'])
            ->get()
            ->toArray();
    }

    public function removeFromCart(int $userId, int $cartId): void
    {
        Cart::where('id', $cartId)->where('user_id', $userId)->delete();
    }

    public static function normalizeQuantity(mixed $quantity): int
    {
        $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 1 || $quantity > 999) {
            throw new \InvalidArgumentException('quantity must be an integer between 1 and 999');
        }
        return $quantity;
    }

    public function updateQuantity(int $userId, int $cartId, mixed $quantity): Cart
    {
        $quantity = self::normalizeQuantity($quantity);

        $cart = Cart::where('id', $cartId)->where('user_id', $userId)->first();
        if (!$cart) {
            throw new \InvalidArgumentException('Cart item not found');
        }

        $cart->quantity = $quantity;
        $cart->save();

        return $cart;
    }
}
