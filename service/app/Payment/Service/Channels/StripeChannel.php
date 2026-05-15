<?php

namespace App\Payment\Service\Channels;

use App\Payment\Model\PaymentChannel;
use App\Payment\Model\PaymentTransaction;
use App\Order\Model\Order;
use App\Order\Model\OrderTimeline;
use App\Payment\Event\OrderPaid;
use Illuminate\Support\Facades\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Webhook;

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
            $secretKey = getenv('STRIPE_SECRET_KEY') ?: null;
            $this->stripe = new StripeClient($secretKey);
        }
        return $this->stripe;
    }

    public function createPaymentIntent(Order $order): array
    {
        try {
            $intent = $this->stripe()->paymentIntents->create([
                'amount' => (int) round($order->total * 100),
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
                'channel_fee' => '0',
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

        try {
            if ($webhookSecret) {
                $event = Webhook::constructEvent($payload, $signature, $webhookSecret);
            } else {
                $event = json_decode($payload, false);
                if (!$event || empty($event->type)) {
                    return;
                }
            }
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return;
        }

        $type = $event->type ?? '';
        $paymentIntent = $event->data->object ?? null;

        if ($type === 'payment_intent.succeeded') {
            $intentId = $paymentIntent->id ?? '';
            $orderId = (int) ($paymentIntent->metadata->order_id ?? 0);

            $txn = PaymentTransaction::where('transaction_no', $intentId)
                ->where('order_id', $orderId)
                ->first();

            if (!$txn || $txn->status !== 'pending') {
                return;
            }

            $this->confirmPayment($intentId, $orderId);
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

    public function confirmPayment(string $transactionNo, int $orderId): void
    {
        $txn = PaymentTransaction::where('transaction_no', $transactionNo)
            ->where('order_id', $orderId)
            ->where('status', 'pending')
            ->firstOrFail();

        $txn->update(['status' => 'success', 'callback_at' => now()]);

        $order = Order::findOrFail($orderId);
        $order->update(['status' => 'paid', 'paid_at' => now()]);

        OrderTimeline::create([
            'order_id' => $orderId,
            'status' => 'paid',
            'operator' => 'payment',
            'remark' => 'Payment confirmed via Stripe',
        ]);

        if (class_exists(Event::class)) {
            Event::dispatch(new OrderPaid($order));
        }
    }
}
