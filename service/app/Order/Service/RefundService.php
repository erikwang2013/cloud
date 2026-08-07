<?php
namespace App\Order\Service;

use App\Order\Model\Order;
use App\Order\Model\Refund;
use App\Payment\Model\PaymentTransaction;
use App\User\Model\UserBalance;
use App\User\Model\UserBalanceLog;
use Illuminate\Database\Capsule\Manager as Capsule;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use support\Log;

/**
 * 退款执行器：Refund 记录 pending → 调 Stripe 退款 API →
 * 成功标记 refunded 并写 UserBalanceLog（如已扣款则加回余额）→ 失败标记 failed。
 */
class RefundService
{
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

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
    public function execute(Order $order, float $amount, ?string $reason, int $operatorId): Refund
    {
        $refund = Refund::create([
            'order_id'   => $order->id,
            'user_id'    => $order->user_id,
            'amount'     => $amount,
            'reason'     => $reason,
            'status'     => 'pending',
            'handled_by' => $operatorId,
        ]);

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
            $this->creditBackIfDeducted($order, $refund->amount);
        });

        return $refund->refresh();
    }

    /**
     * 若该订单此前已从用户余额扣款，则加回余额并写余额流水。
     */
    private function creditBackIfDeducted(Order $order, float $amount): void
    {
        $deduction = UserBalanceLog::where('order_id', $order->id)
            ->where('amount', '>', 0)
            ->first();

        if (!$deduction) {
            return;
        }

        $balance = UserBalance::where('user_id', $order->user_id)
            ->where('currency', $order->currency)
            ->lockForUpdate()
            ->first();

        if (!$balance) {
            return;
        }

        $balance->increment('balance', $amount);
        UserBalanceLog::create([
            'user_id'        => $order->user_id,
            'type'           => 'refund',
            'currency'       => $order->currency,
            'amount'         => $amount,
            'balance_before' => (string) bcsub((string) $balance->balance, (string) $amount, 4),
            'balance_after'  => (string) $balance->balance,
            'order_id'       => $order->id,
            'remark'         => "Refund for order {$order->order_no}",
        ]);
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

    private function toSmallestUnit(float $amount, string $currency): int
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount);
        }
        return (int) round((float) bcmul((string) $amount, '100', 2));
    }
}
