<?php
namespace App\Order\Service;

use App\Order\Model\Order;
use App\Order\Model\OrderItem;
use App\Order\Model\OrderTimeline;
use App\Order\Model\Cart;
use App\Product\Model\ProductRegion;
use Common\Money\Money;
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
        $quantity = (int) ($data['quantity'] ?? 1);
        if ($quantity < 1 || $quantity > 999) {
            throw new \InvalidArgumentException('quantity must be an integer between 1 and 999');
        }

        Cart::updateOrCreate(
            [
                'user_id'   => $userId,
                'sku_id'    => $data['sku_id'],
                'region_id' => $data['region_id'],
            ],
            [
                'quantity' => $quantity,
                'cycle'    => $data['cycle'] ?? 'monthly',
            ]
        );
    }

    public function createFromCart(int $userId, array $cartIds, string $currency = 'USD', ?string $couponCode = null): Order
    {
        return DB::transaction(function () use ($userId, $cartIds, $currency, $couponCode) {
            // 幂等守卫：下单事务内行锁重读 cart（事务提交即清空 = 状态转移）。
            // 并发重复提交时后到事务阻塞于此，前事务提交后重读为空集即抛错，同一 cart 只出一单（防 TOCTOU）。
            $carts = Cart::whereIn('id', $cartIds)
                ->where('user_id', $userId)
                ->with(['sku.product'])
                ->lockForUpdate()
                ->get();

            if ($carts->isEmpty()) {
                throw new \InvalidArgumentException('Cart is empty');
            }

            $subtotal = '0';
            $items = [];

            foreach ($carts as $cart) {
                if ((int) $cart->quantity < 1 || (int) $cart->quantity > 999) {
                    throw new \InvalidArgumentException("Invalid quantity for SKU {$cart->sku_id}");
                }

                $regionPrice = ProductRegion::where('sku_id', $cart->sku_id)
                    ->where('region_id', $cart->region_id)
                    ->where('currency', $currency)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($regionPrice->stock < $cart->quantity) {
                    throw new \InvalidArgumentException("Insufficient stock for SKU {$cart->sku_id}");
                }

                // D4：行项先乘后 bcround 到 4 位；subtotal 为同精度精确求和
                $totalPrice = Money::bcround(bcmul($regionPrice->price, (string)$cart->quantity, 8), 4);
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

            // 优惠券服务端应用：行锁 + 重校验（防并发超发），核销时递增 used_count 并写入 user_coupons
            $discount = '0';
            $coupon = null;
            if ($couponCode) {
                $coupon = \App\Order\Model\Coupon::where('code', $couponCode)->lockForUpdate()->first();
                if (!$coupon || !$coupon->isValid()) {
                    throw new \InvalidArgumentException('Coupon is invalid or expired');
                }
                $discount = bcadd($discount, $coupon->calculateDiscount($subtotal), 4);
            }
            // D5 恒等式：total = subtotal + tax - discount（同精度加减，精确）；
            // 系统暂无税率来源（无 tax_rate 配置），tax 以 0 参与恒等式，留待税模块接入
            $tax   = '0.0000';
            $total = bcsub(bcadd($subtotal, $tax, 4), $discount, 4);

            $order = Order::create([
                'order_no' => $this->generateOrderNo(),
                'user_id'  => $userId,
                'type'     => 'new',
                'status'   => 'pending',
                'currency' => $currency,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax'      => $tax,
                'total'    => $total,
                'exchange_rate' => $this->getExchangeRate($currency),
            ]);

            if ($coupon) {
                // user_coupons 不加 (user_id, coupon_id) 唯一约束：语义允许同一用户跨订单多次核销同一优惠券
                //（max_uses 为总量上限，无每人限次）；并发由本事务内 coupon 行锁串行化，同一订单只插入一行。
                $coupon->increment('used_count');
                DB::table('user_coupons')->insert([
                    'user_id'    => $userId,
                    'coupon_id'  => $coupon->id,
                    'order_id'   => $order->id,
                    'used_at'    => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

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
