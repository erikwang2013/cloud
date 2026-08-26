<?php
namespace App\order\service;

use App\order\model\Order;
use App\order\model\Refund;
use App\payment\model\PaymentTransaction;
use Common\money\Money;
use Common\webhook\WebhookDispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use support\Log;

/**
 * 退款执行器：Refund 记录 pending → 调 Stripe 退款 API →
 * 成功标记 refunded 并写 UserBalanceLog（如已扣款则加回余额）→ 失败标记 failed。
 */
class RefundService
{
    /**
     * 退款窗口规则：按商品分类 type 返回 [小时数, 越期文案]。
     * hours = 0 表示该类型不可退款；null 表示无窗口限制（disk/未知类型）。
     */
    public static function refundRule(?string $type): ?array
    {
        return match ($type) {
            'server' => ['hours' => 72,  'message' => 'Refund window expired: server orders are refundable within 72 hours of payment'],
            'domain' => ['hours' => 120, 'message' => 'Refund window expired: domain orders are refundable within 5 days of payment'],
            'ip'     => ['hours' => 0,   'message' => 'This product type (IP) is not refundable'],
            default  => null,
        };
    }

    /**
     * 纯函数：返回越期/不可退的错误文案，未超限返回 null。
     */
    public static function windowError(?string $type, int $baseTs, int $nowTs): ?string
    {
        $rule = self::refundRule($type);
        if ($rule === null) {
            return null;
        }
        if ($rule['hours'] === 0 || $nowTs - $baseTs > $rule['hours'] * 3600) {
            return $rule['message'];
        }
        return null;
    }

    private ?StripeClient $stripe = null;

    private function stripe(): StripeClient
    {
        if ($this->stripe === null) {
            $secretKey = getenv('STRIPE_SECRET_KEY');
            $this->stripe = new StripeClient($secretKey ?: null);
        }
        return $this->stripe;
    }

    /**
     * 执行退款全流程。
     *
     * @throws \RuntimeException 当订单无可退款的成功支付交易时抛出
     */
    public function execute(Order $order, string $amount, ?string $reason, int $operatorId): Refund
    {
        $this->assertRefundable($order);

        try {
            $refund = Refund::create([
                'order_id'   => $order->id,
                'user_id'    => $order->user_id,
                'amount'     => $amount,
                'reason'     => $reason,
                'status'     => 'pending',
                'handled_by' => $operatorId,
            ]);
        } catch (QueryException $e) {
            // pending 态由 refunds.pending_order_id 唯一约束互斥，冲突即并发重复退款
            if (($e->errorInfo[0] ?? '') === '23000') {
                throw new \InvalidArgumentException('A refund is already pending for this order');
            }
            throw $e;
        }

        $txn = PaymentTransaction::where('order_id', $order->id)
            ->where('status', 'success')
            ->first();

        if (!$txn) {
            $this->markFailed($refund, 'No successful payment transaction found for order');
            throw new \RuntimeException('No successful payment transaction found for order ' . $order->id);
        }

        try {
            $this->stripe()->refunds->create([
                'payment_intent' => $txn->transaction_no,
                'amount'         => $this->toSmallestUnit($amount, $order->currency),
                'reason'         => 'requested_by_customer',
                'idempotency_key' => 'refund_' . $refund->id,
                'metadata'       => [
                    'order_id'   => $order->id,
                    'refund_id'  => $refund->id,
                ],
            ]);
        } catch (ApiErrorException $e) {
            $this->markFailed($refund, $e->getMessage());
            Log::error("Stripe refund failed: refund={$refund->id} order={$order->id}: {$e->getMessage()}");
            throw new \RuntimeException('Stripe refund failed: ' . $e->getMessage(), 0, $e);
        }

        Capsule::transaction(function () use ($refund, $order) {
            // 原子更新：仅 pending 可转 refunded，避免并发重复退款
            $affected = Capsule::table('refunds')
                ->where('id', $refund->id)
                ->where('status', 'pending')
                ->update(['status' => 'refunded', 'updated_at' => date('Y-m-d H:i:s')]);

            if ($affected === 0) {
                return;
            }

            $order->update(['status' => 'refunded']);
        });

        $refund = $refund->refresh();

        if ($refund->status === 'refunded') {
            WebhookDispatcher::dispatch(WebhookDispatcher::EVENT_ORDER_REFUNDED, [
                'order_id'  => $order->id,
                'refund_id' => $refund->id,
                'amount'    => (string) $refund->amount,
                'currency'  => $order->currency,
            ]);
        }

        return $refund;
    }

    /**
     * 退款前置校验：状态、重复退款、退款窗口（锚点 paid_at，未支付则为 created_at）。
     * 不满足时抛 InvalidArgumentException，供所有入口（含直接调 service）共用。
     */
    public function assertRefundable(Order $order): void
    {
        if (!in_array($order->status, ['paid', 'completed'], true)) {
            throw new \InvalidArgumentException('Order cannot be refunded');
        }
        if (Refund::where('order_id', $order->id)->where('status', 'refunded')->exists()) {
            throw new \InvalidArgumentException('Order has already been refunded');
        }

        $base = $order->paid_at ?? $order->created_at;
        $baseTs = $base instanceof \DateTimeInterface
            ? $base->getTimestamp()
            : (int) strtotime((string) $base);

        foreach ($order->items as $item) {
            $type = $item->sku?->product?->category?->type;
            $error = self::windowError($type, $baseTs, time());
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }
    }

    private function markFailed(Refund $refund, string $reason): void
    {
        Capsule::table('refunds')
            ->where('id', $refund->id)
            ->where('status', 'pending')
            ->update([
                'status'        => 'failed',
                'reject_reason' => substr($reason, 0, 255),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
    }

    public function toSmallestUnit(string $amount, string $currency): int
    {
        return Money::toSmallestUnit($amount, $currency);
    }
}
