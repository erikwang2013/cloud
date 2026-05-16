<?php
namespace App\Order\Service;

use App\Order\Model\Order;
use App\Order\Model\OrderItem;
use App\Order\Model\OrderTimeline;
use App\Order\Model\Cart;
use App\Product\Model\ProductRegion;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Facades\Redis;

class OrderService
{
    private function generateOrderNo(): string
    {
        return date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    public function addToCart(int $userId, array $data): void
    {
        Cart::updateOrCreate(
            [
                'user_id'   => $userId,
                'sku_id'    => $data['sku_id'],
                'region_id' => $data['region_id'],
            ],
            [
                'quantity' => $data['quantity'] ?? 1,
                'cycle'    => $data['cycle'] ?? 'monthly',
            ]
        );
    }

    public function createFromCart(int $userId, array $cartIds, string $currency = 'USD'): Order
    {
        $carts = Cart::whereIn('id', $cartIds)
            ->where('user_id', $userId)
            ->with(['sku.product'])
            ->get();

        if ($carts->isEmpty()) {
            throw new \InvalidArgumentException('Cart is empty');
        }

        return DB::transaction(function () use ($userId, $carts, $currency) {
            $subtotal = '0';
            $items = [];

            foreach ($carts as $cart) {
                $regionPrice = ProductRegion::where('sku_id', $cart->sku_id)
                    ->where('region_id', $cart->region_id)
                    ->where('currency', $currency)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($regionPrice->stock < $cart->quantity) {
                    throw new \InvalidArgumentException("Insufficient stock for SKU {$cart->sku_id}");
                }

                $totalPrice = bcmul($regionPrice->price, (string)$cart->quantity, 4);
                $subtotal   = bcadd($subtotal, $totalPrice, 4);

                $items[] = [
                    'sku_id'     => $cart->sku_id,
                    'region_id'  => $cart->region_id,
                    'product_id' => $cart->sku->product_id,
                    'quantity'   => $cart->quantity,
                    'cycle'      => $cart->cycle,
                    'unit_price' => $regionPrice->price,
                    'total_price'=> $totalPrice,
                    'resource_snapshot' => json_encode([
                        'specs' => $cart->sku->specs,
                        'region'=> $cart->region_id,
                    ]),
                ];
            }

            $order = Order::create([
                'order_no' => $this->generateOrderNo(),
                'user_id'  => $userId,
                'type'     => 'new',
                'status'   => 'pending',
                'currency' => $currency,
                'subtotal' => $subtotal,
                'total'    => $subtotal,
                'exchange_rate' => $this->getExchangeRate($currency),
            ]);

            foreach ($items as $item) {
                $item['order_id'] = $order->id;
                OrderItem::create($item);
            }

            OrderTimeline::create([
                'order_id' => $order->id,
                'status'   => 'pending',
                'operator' => 'system',
                'remark'   => 'Order created',
            ]);

            Cart::whereIn('id', $cartIds)->delete();

            foreach ($carts as $cart) {
                ProductRegion::where('sku_id', $cart->sku_id)
                    ->where('region_id', $cart->region_id)
                    ->decrement('stock', $cart->quantity);
            }

            return $order->load('items');
        });
    }

    private function getExchangeRate(string $currency): string
    {
        if ($currency === 'USD') return '1.000000';
        try {
            $rate = Redis::get("exchange_rate:{$currency}");
            return $rate ?: '1.000000';
        } catch (\Exception $e) {
            return '1.000000';
        }
    }
}
