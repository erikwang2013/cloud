<?php

namespace App\Payment\Service\Channels;

use App\Payment\Model\PaymentChannel;
use App\Payment\Model\PaymentTransaction;
use App\Payment\Service\PaymentRouter;
use App\Order\Model\Order;
use App\Order\Model\OrderTimeline;
use App\Payment\Event\OrderPaid;
use Common\Money\Money;
use Common\Webhook\WebhookDispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Webhook;
use support\Log;

class StripeChannel
{
    private PaymentChannel $channel;
    private ?StripeClient $stripe = null;

    public function __construct(PaymentChannel $channel)
    {
        $this->channel = $channel;
    }

    private function stripe(): StripeClient
    {
        if ($this->stripe === null) {
            $secretKey = getenv('STRIPE_SECRET_KEY');
            $this->stripe = new StripeClient($secretKey ?: null);
        }
        return $this->stripe;
    }

    public function createPaymentIntent(Order $order): array
    {
        $amount = Money::toSmallestUnit($order->total, $order->currency);

        $fee = (new PaymentRouter())->calculateFee($order->total, $this->channel->fee_config ?? []);

        try {
            $intent = $this->stripe()->paymentIntents->create([
                'amount' => $amount,
                'currency' => strtolower($order->currency),
                'metadata' => [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                ],
            ]);

            PaymentTransaction::create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'channel_id' => $this->channel->id,
                'amount' => $order->total,
                'currency' => $order->currency,
                'exchange_rate' => $order->exchange_rate,
                'channel_fee' => $fee,
                'transaction_no' => $intent->id,
                'status' => 'pending',
            ]);

            return [
                'client_secret' => $intent->client_secret,
                'transaction_id' => $intent->id,
            ];
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe PaymentIntent creation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function handleWebhook(string $payload, string $signature): void
    {
        $webhookSecret = getenv('STRIPE_WEBHOOK_SECRET');

        if (!$webhookSecret) {
            Log::error('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not configured');
            return;
        }

        try {
            $event = Webhook::constructEvent($payload, $signature, $webhookSecret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed: ' . $e->getMessage());
            return;
        }

        $type = $event->type ?? '';
        $paymentIntent = $event->data->object ?? null;

        if ($type === 'payment_intent.succeeded') {
            $intentId = $paymentIntent->id ?? '';
            $orderId = (int) ($paymentIntent->metadata->order_id ?? 0);
            $amountReceived = (int) ($paymentIntent->amount_received ?? 0);
            $stripeCurrency = strtoupper($paymentIntent->currency ?? '');

            $txn = PaymentTransaction::where('transaction_no', $intentId)
                ->where('order_id', $orderId)
                ->first();

            if (!$txn || $txn->status !== 'pending') {
                return;
            }

            // Verify webhook amount matches stored transaction amount
            $expectedSmallest = Money::toSmallestUnit($txn->amount, $txn->currency);
            if ($amountReceived !== $expectedSmallest || strtoupper($txn->currency) !== $stripeCurrency) {
                Log::error("Stripe amount mismatch: txn={$txn->id} expected={$expectedSmallest} received={$amountReceived}");
                $txn->update(['status' => 'failed']);
                return;
            }

            try {
                $this->confirmPayment($intentId, $orderId);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                return;
            }
        }

        if ($type === 'payment_intent.payment_failed') {
            $intentId = $paymentIntent->id ?? '';
            $orderId = (int) ($paymentIntent->metadata->order_id ?? 0);

            PaymentTransaction::where('transaction_no', $intentId)
                ->where('order_id', $orderId)
                ->where('status', 'pending')
                ->update(['status' => 'failed']);
        }
    }

    private function confirmPayment(string $transactionNo, int $orderId): void
    {
        $order = null;
        Capsule::transaction(function () use ($transactionNo, $orderId, &$order) {
            // 原子抢占：Stripe 重试并发投递时仅一个请求能 pending->success
            $affected = Capsule::table('payment_transactions')
                ->where('transaction_no', $transactionNo)
                ->where('order_id', $orderId)
                ->where('status', 'pending')
                ->update(['status' => 'success', 'callback_at' => now()]);

            if ($affected === 0) {
                return;
            }

            $order = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'paid') {
                $order->update(['status' => 'paid', 'paid_at' => now()]);

                OrderTimeline::create([
                    'order_id' => $orderId,
                    'status' => 'paid',
                    'operator' => 'payment',
                    'remark' => 'Payment confirmed via Stripe',
                ]);
            }
        });

        if ($order && class_exists(Event::class)) {
            Event::dispatch(new OrderPaid($order));

            WebhookDispatcher::dispatch(WebhookDispatcher::EVENT_ORDER_PAID, [
                'order_id' => $order->id,
                'amount'   => (string) $order->total,
                'currency' => $order->currency,
            ]);
        }
    }

    // 统一走 Common\Money（原 toSmallestUnit/isZeroDecimal/smallestToMajor 已合并，此处仅留委托保持调用方兼容）
    public static function isZeroDecimal(string $currency): bool
    {
        return Money::isZeroDecimal($currency);
    }

    public static function smallestToMajor(int $smallest, string $currency): string
    {
        return Money::smallestToMajor($smallest, $currency);
    }

    /**
     * 拉取某自然日（UTC 边界）已 succeeded 的 PaymentIntent，按币种汇总实收金额（major unit）。
     * 本地 created_at 按服务器时区查询，此处按 UTC 日界拉 Stripe 报表，服务器时区接近 UTC 时两者一致。
     */
    public function fetchReport(string $date): array
    {
        if (!getenv('STRIPE_SECRET_KEY')) {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured');
        }

        $from = strtotime($date . ' 00:00:00 UTC');
        $to   = $from + 86400;

        $totals = [];
        $count  = 0;
        $intents = $this->stripe()->paymentIntents->all([
            'created' => ['gte' => $from, 'lt' => $to],
            'status'  => 'succeeded',
            'limit'   => 100,
        ]);

        foreach ($intents->autoPagingIterator() as $pi) {
            $currency = strtoupper((string) ($pi->currency ?? ''));
            if ($currency === '') {
                continue;
            }
            $amount = (int) ($pi->amount_received ?? $pi->amount ?? 0);
            $totals[$currency] = bcadd($totals[$currency] ?? '0', self::smallestToMajor($amount, $currency), 4);
            $count++;
        }

        return ['by_currency' => $totals, 'count' => $count];
    }
}
