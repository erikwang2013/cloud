<?php
namespace App\Payment\Service\Channels;

use App\Payment\Model\PaymentChannel;
use App\Payment\Model\PaymentTransaction;
use App\Order\Model\Order;
use App\Order\Model\OrderTimeline;
use App\Payment\Event\OrderPaid;

class StripeChannel
{
    private PaymentChannel $channel;

    public function __construct(PaymentChannel $channel)
    {
        $this->channel = $channel;
    }

    public function createPaymentIntent(Order $order): array
    {
        // In production: use Stripe\StripeClient with $this->channel->api_key_encrypted
        $intentId = 'pi_' . bin2hex(random_bytes(12));

        PaymentTransaction::create([
            'order_id'       => $order->id,
            'user_id'        => $order->user_id,
            'channel_id'     => $this->channel->id,
            'amount'         => $order->total,
            'currency'       => $order->currency,
            'exchange_rate'  => $order->exchange_rate,
            'channel_fee'    => '0',
            'transaction_no' => $intentId,
            'status'         => 'pending',
        ]);

        return [
            'client_secret'  => 'cs_' . bin2hex(random_bytes(16)),
            'transaction_id' => $intentId,
        ];
    }

    public function handleWebhook(string $payload, string $signature): void
    {
        $event = json_decode($payload, true);
        if (!$event || ($event['type'] ?? '') !== 'payment_intent.succeeded') {
            return;
        }

        $intentId  = $event['data']['object']['id'] ?? '';
        $orderId   = $event['data']['object']['metadata']['order_id'] ?? 0;
        $this->confirmPayment($intentId, (int)$orderId);
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
            'status'   => 'paid',
            'operator' => 'payment',
            'remark'   => 'Payment confirmed via Stripe',
        ]);

        if (class_exists(\Illuminate\Support\Facades\Event::class)) {
            \Illuminate\Support\Facades\Event::dispatch(new OrderPaid($order));
        }
    }
}
