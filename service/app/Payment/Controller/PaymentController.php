<?php
namespace App\Payment\Controller;

use App\Payment\Service\PaymentRouter;
use App\Payment\Service\Channels\StripeChannel;
use App\Payment\Model\PaymentChannel;
use App\Payment\Model\PaymentTransaction;
use App\Order\Model\Order;
use Common\Helper\Response;

class PaymentController
{
    public function availableChannels($request, int $orderId)
    {
        $order = Order::where('user_id', $request->userId)->findOrFail($orderId);
        if ($order->status !== 'pending') {
            return json(Response::error(422, 'Order cannot be paid'));
        }

        $router = new PaymentRouter();
        $channels = $router->getAvailableChannels([
            'amount'   => $order->total,
            'currency' => $order->currency,
            'region'   => 'global',
        ]);

        return json(Response::success($channels));
    }

    public function pay($request, int $orderId)
    {
        $channelId = $request->input('channel_id');

        // 行锁串行化同订单并发支付发起（双击/重放），锁内校验状态与已有 intent；
        // 单机与分布式部署同为 MySQL 行锁，语义一致。
        return \Illuminate\Database\Capsule\Manager::transaction(function () use ($request, $orderId, $channelId) {
            // $orderId 已由路由层 hashids 解码，直接作为 id 条件；缺 id 条件时行锁只会
            // 取该用户任意一条 pending 订单，导致支付订单 B 却按订单 A 金额扣款
            $order = Order::where('user_id', $request->userId)
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($order->status !== 'pending') {
                return json(Response::error(422, 'Order cannot be paid'));
            }

            $channel = PaymentChannel::findOrFail($channelId);
            if ($channel->code !== 'stripe') {
                return json(Response::error(422, 'Unsupported payment channel'));
            }

            // 已有待支付/已成功的 intent 则拒绝重复发起；已 failed/cancelled 的可重试
            // 实际写入状态为 success（见 StripeChannel::confirmPayment），非 succeeded
            $exists = PaymentTransaction::where('order_id', $order->id)
                ->whereIn('status', ['pending', 'success'])
                ->exists();
            if ($exists) {
                return json(Response::error(422, 'Payment already initiated for this order'));
            }

            $stripeChannel = new StripeChannel($channel);
            $result = $stripeChannel->createPaymentIntent($order);
            return json(Response::success($result));
        });
    }

    public function stripeWebhook($request)
    {
        $payload   = $request->rawBody();
        $signature = $request->header('Stripe-Signature', '');

        $channel = PaymentChannel::where('code', 'stripe')->firstOrFail();
        $stripeChannel = new StripeChannel($channel);
        $stripeChannel->handleWebhook($payload, $signature);

        return json(Response::success());
    }
}
