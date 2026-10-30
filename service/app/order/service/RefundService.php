<?php
namespace App\order\service;

use App\order\model\Order;
use App\order\model\Refund;
use App\payment\model\PaymentTransaction;
use Common\money\Money;
use Common\security\AuditLogger;
use Common\webhook\WebhookDispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use support\Log;

/**
 * 退款执行器：Refund 记录 pending → 调 Stripe 退款 API →
 * 成功原子标记 refunded 并同步订单状态 → 失败标记 failed。
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

    private ?StripeClient $stripe;

    public function __construct(?StripeClient $stripe = null)
    {
        $this->stripe = $stripe;
    }

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
        if ($operatorId <= 0) {
            throw new \InvalidArgumentException('Refund operator is required');
        }

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

        return $this->executePending($refund, $operatorId);
    }

    /**
     * 执行 pending/failed 退款单：admin 审批与失败重试共用入口。
     * 成功原子置 refunded 并同步订单；业务错误抛 InvalidArgumentException，外部支付错误抛 RuntimeException。
     */
    public function executePending(Refund $refund, int $operatorId): Refund
    {
        if ($operatorId <= 0) {
            throw new \InvalidArgumentException('Refund operator is required');
        }

        if (!in_array($refund->status, ['pending', 'failed'], true)) {
            throw new \InvalidArgumentException('Refund cannot be executed in current status');
        }

        $order = $refund->order;
        if (!$order) {
            $this->markFailed($refund, 'Order not found');
            throw new \RuntimeException('Order not found for refund ' . $refund->id);
        }

        // 操作人留痕；归属以订单为准（后台建单时 user_id 可能缺失）
        $refund->update(['handled_by' => $operatorId]);
        if ((int) $refund->user_id !== (int) $order->user_id) {
            $refund->update(['user_id' => $order->user_id]);
        }

        try {
            $this->assertRefundable($order);
        } catch (\InvalidArgumentException $e) {
            // 业务上不可退：置 failed 释放 pending_order_id 唯一键，允许修复后重新发起
            $this->markFailed($refund, $e->getMessage());
            throw $e;
        }

        $txn = PaymentTransaction::where('order_id', $order->id)
            ->where('status', 'success')
            ->first();

        if (!$txn) {
            $this->markFailed($refund, 'No successful payment transaction found for order');
            throw new \RuntimeException('No successful payment transaction found for order ' . $order->id);
        }

        if (strtoupper((string) $txn->currency) !== strtoupper((string) $order->currency)) {
            $this->markFailed($refund, 'Payment currency mismatch');
            throw new \RuntimeException('Payment currency mismatch for order ' . $order->id);
        }

        $error = self::amountError((string) $refund->amount, (string) $txn->amount);
        if ($error !== null) {
            $this->markFailed($refund, $error);
            throw new \InvalidArgumentException($error);
        }

        $expectedSmallest = $this->toSmallestUnit((string) $refund->amount, $order->currency);

        // 先查 Stripe 是否已有该 payment_intent 的退款：覆盖“Stripe 成功但本地回填失败”的崩溃窗口，
        // 超过 Stripe 24h 幂等键窗口后重试也不会二次退款。
        $stripeRefund = $this->findExistingStripeRefund($txn->transaction_no, $expectedSmallest);

        try {
            if ($stripeRefund === null) {
                // idempotency_key 必须作为第二个 $opts 参数；订单维度键让 24h 内重试去重
                $stripeRefund = $this->stripe()->refunds->create([
                    'payment_intent' => $txn->transaction_no,
                    'amount'         => $expectedSmallest,
                    'reason'         => 'requested_by_customer',
                    'metadata'       => [
                        'order_id'  => $order->id,
                        'refund_id' => $refund->id,
                    ],
                ], ['idempotency_key' => 'refund_order_' . $order->id]);
            }
        } catch (ApiErrorException $e) {
            $this->markFailed($refund, $e->getMessage());
            Log::error("Stripe refund failed: refund={$refund->id} order={$order->id}: {$e->getMessage()}");
            throw new \RuntimeException('Stripe refund failed: ' . $e->getMessage(), 0, $e);
        }

        $updated = Capsule::transaction(function () use ($refund, $order, $stripeRefund) {
            // 原子更新：pending/failed 才能转 refunded，避免并发重复退款
            $affected = Capsule::table('refunds')
                ->where('id', $refund->id)
                ->whereIn('status', ['pending', 'failed'])
                ->update([
                    'status' => 'refunded',
                    'stripe_refund_id' => $stripeRefund->id,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            if ($affected === 0) {
                return false;
            }

            $order->update(['status' => 'refunded']);
            return true;
        });

        $refund = $refund->refresh();

        if (!$updated) {
            if ($refund->status !== 'refunded') {
                throw new \RuntimeException('Refund status changed concurrently');
            }
            // 并发请求已由另一方完成状态流转：不得重复派发 order.refunded
            return $refund;
        }

        AuditLogger::record('refund_executed', [
            'user_id' => $order->user_id,
            'input'   => [
                'refund_id'   => $refund->id,
                'order_id'    => $order->id,
                'amount'      => (string) $refund->amount,
                'currency'    => $order->currency,
                'operator_id' => $operatorId,
            ],
        ]);

        WebhookDispatcher::dispatch(WebhookDispatcher::EVENT_ORDER_REFUNDED, [
            'order_id'  => $order->id,
            'refund_id' => $refund->id,
            'amount'    => (string) $refund->amount,
            'currency'  => $order->currency,
        ]);

        return $refund;
    }

    /**
     * 查找该 payment_intent 已存在的成功/处理中退款，金额必须与本次全额一致。
     */
    private function findExistingStripeRefund(string $paymentIntent, int $expectedSmallest): ?object
    {
        try {
            $refunds = $this->stripe()->refunds->all([
                'payment_intent' => $paymentIntent,
                'limit'          => 10,
            ]);
            foreach ($refunds->autoPagingIterator() as $existing) {
                // 只复用已成功的退款；pending 不能提前把本地置 refunded
                if (($existing->status ?? '') === 'succeeded'
                    && (int) ($existing->amount ?? 0) === $expectedSmallest) {
                    return $existing;
                }
            }
        } catch (\Throwable $e) {
            // 查询失败不阻塞正常退款：继续走 create + idempotency_key
        }
        return null;
    }

    /**
     * 纯函数：金额为正、最多 4 位小数，且与成功支付金额全额相等。
     * 本系统只支持整单退款；部分退款会把订单整体置 refunded，造成状态不一致。
     */
    public static function amountError(string $amount, string $paid): ?string
    {
        if (!preg_match('/^\d+(\.\d{1,4})?$/D', $amount)) {
            return 'Invalid refund amount';
        }
        if (bccomp($amount, '0', 4) <= 0) {
            return 'Refund amount must be positive';
        }
        if (bccomp($amount, $paid, 4) !== 0) {
            return 'Refund amount must equal paid amount';
        }
        return null;
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
        $affected = Capsule::table('refunds')
            ->where('id', $refund->id)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status'        => 'failed',
                'reject_reason' => substr($reason, 0, 255),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

        if ($affected > 0) {
            AuditLogger::record('refund_failed', [
                'user_id' => $refund->user_id,
                'input'   => [
                    'refund_id' => $refund->id,
                    'order_id'  => $refund->order_id,
                    'reason'    => $reason,
                ],
                'status'  => 'failed',
            ]);
        }
    }

    public function toSmallestUnit(string $amount, string $currency): int
    {
        return Money::toSmallestUnit($amount, $currency);
    }
}
