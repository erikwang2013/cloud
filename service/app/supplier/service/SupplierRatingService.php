<?php
namespace App\supplier\service;

use App\supplier\model\SupplierRating;
use App\supplier\model\Supplier;
use App\order\model\Order;
use App\order\model\OrderItem;

class SupplierRatingService
{
    // 与 RefundService 的订单可操作状态集保持一致：已支付或已完成交付才允许评分
    private const RATEABLE_ORDER_STATUSES = ['paid', 'completed'];

    public function rate(int $userId, int $supplierId, int $orderId, array $data): SupplierRating
    {
        $existing = SupplierRating::where('user_id', $userId)
            ->where('order_id', $orderId)
            ->first();
        if ($existing) {
            throw new \RuntimeException('You have already rated this order');
        }

        $order = Order::find($orderId);
        if (!$order) {
            throw new \RuntimeException('Order not found');
        }
        if ((int) $order->user_id !== $userId) {
            throw new \RuntimeException('You can only rate your own orders');
        }
        if (!in_array($order->status, self::RATEABLE_ORDER_STATUSES, true)) {
            throw new \RuntimeException('Order status does not allow rating');
        }

        // 订单必须包含该供应商的商品（order_items→product_skus→products.supplier_id）
        $hasSupplierProduct = OrderItem::where('order_id', $orderId)
            ->whereHas('sku', function ($q) use ($supplierId) {
                $q->whereHas('product', function ($q2) use ($supplierId) {
                    $q2->where('supplier_id', $supplierId);
                });
            })
            ->exists();
        if (!$hasSupplierProduct) {
            throw new \RuntimeException('Order does not contain products from this supplier');
        }

        // rating 强制 1-5；子维度 0-5（0 = 未评分，与省略等价，对齐 DB 默认语义）
        $scoreRanges = ['rating' => 1, 'quality' => 0, 'support' => 0, 'delivery_speed' => 0, 'value' => 0];
        foreach ($scoreRanges as $scoreField => $min) {
            if (isset($data[$scoreField]) && $data[$scoreField] !== '') {
                $score = (int) $data[$scoreField];
                if ($score < $min || $score > 5) {
                    throw new \RuntimeException('Rating scores must be between ' . $min . ' and 5');
                }
            }
        }

        $rating = SupplierRating::create([
            'supplier_id'    => $supplierId,
            'user_id'        => $userId,
            'order_id'       => $orderId,
            'rating'         => $data['rating'] ?? 5,
            'quality'        => $data['quality'] ?? 0,
            'support'        => $data['support'] ?? 0,
            'delivery_speed' => $data['delivery_speed'] ?? 0,
            'value'          => $data['value'] ?? 0,
            'content'        => $data['content'] ?? null,
            'status'         => 'published',
        ]);

        $this->recomputeSupplierAvg($supplierId);

        // 通知供应商收到新评分（通知非关键，失败不影响评分结果）
        try {
            $supplier = Supplier::find($supplierId);
            if ($supplier && $supplier->user_id) {
                (new \App\notification\service\NotificationDispatcher())->dispatch(
                    $supplier->user_id, 'rating_received',
                    ['rating' => (string) ($data['rating'] ?? 5)],
                    ['email', 'in_app']
                );
            }
        } catch (\Throwable) {
            // 忽略通知异常
        }

        return $rating;
    }

    public function recomputeSupplierAvg(int $supplierId): void
    {
        $stats = SupplierRating::where('supplier_id', $supplierId)
            ->where('status', 'published')
            ->selectRaw('AVG(rating) as avg, COUNT(*) as cnt')
            ->first();

        Supplier::where('id', $supplierId)->update([
            'rating_avg'   => round($stats->avg ?? 0, 2),
            'rating_count' => $stats->cnt ?? 0,
        ]);
    }

    public function listForSupplier(int $supplierId, int $limit = 20): array
    {
        return SupplierRating::where('supplier_id', $supplierId)
            ->where('status', 'published')
            ->with('user:id,nickname,avatar')
            ->orderBy('created_at', 'desc')
            ->paginate($limit)
            ->toArray();
    }

    public function approve(int $ratingId): void
    {
        SupplierRating::where('id', $ratingId)->update(['status' => 'published']);
        $rating = SupplierRating::find($ratingId);
        if ($rating) {
            $this->recomputeSupplierAvg($rating->supplier_id);
        }
    }

    public function hide(int $ratingId): void
    {
        SupplierRating::where('id', $ratingId)->update(['status' => 'hidden']);
        $rating = SupplierRating::find($ratingId);
        if ($rating) {
            $this->recomputeSupplierAvg($rating->supplier_id);
        }
    }
}
