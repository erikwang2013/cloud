<?php

namespace App\Payment\Service\Channels;

use App\Payment\Model\PaymentChannel;
use App\Payment\Model\PaymentTransaction;
use App\Order\Model\Order;
use App\Order\Model\OrderTimeline;
use App\Payment\Event\OrderPaid;
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

    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

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
        $amount = $this->toSmallestUnit($order->total, $order->currency);

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
                'channel_fee' => 0,
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
            $expectedSmallest = $this->toSmallestUnit($txn->amount, $txn->currency);
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
        }
    }

    private function toSmallestUnit(string $total, string $currency): int
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round((float) $total);
        }
        return (int) round((float) bcmul($total, '100', 2));
    }
}
