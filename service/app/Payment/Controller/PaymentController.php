<?php
namespace App\Payment\Controller;

use App\Payment\Service\PaymentRouter;
use App\Payment\Service\Channels\StripeChannel;
use App\Payment\Model\PaymentChannel;
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
        $order     = Order::where('user_id', $request->userId)->findOrFail($orderId);
        $channelId = $request->input('channel_id');

        $channel = PaymentChannel::findOrFail($channelId);

        if ($channel->code === 'stripe') {
            $stripeChannel = new StripeChannel($channel);
            $result = $stripeChannel->createPaymentIntent($order);
            return json(Response::success($result));
        }

        return json(Response::error(422, 'Unsupported payment channel'));
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
