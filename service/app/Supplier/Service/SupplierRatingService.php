<?php
namespace App\Supplier\Service;

use App\Supplier\Model\SupplierRating;
use App\Supplier\Model\Supplier;
use App\Order\Model\OrderItem;
use Illuminate\Database\Capsule\Manager as Capsule;

class SupplierRatingService
{
    public function rate(int $userId, int $supplierId, int $orderId, array $data): SupplierRating
    {
        $existing = SupplierRating::where('user_id', $userId)
            ->where('order_id', $orderId)
            ->first();
        if ($existing) {
            throw new \RuntimeException('You have already rated this order');
        }

        $hasPurchased = OrderItem::whereHas('order', function ($q) use ($userId, $orderId) {
            $q->where('user_id', $userId)->where('id', $orderId);
        })->exists();

        if (!$hasPurchased) {
            throw new \RuntimeException('You can only rate products you have purchased');
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
                (new \App\Notification\Service\NotificationDispatcher())->dispatch(
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
