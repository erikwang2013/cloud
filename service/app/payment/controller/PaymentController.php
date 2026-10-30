<?php
namespace App\payment\controller;

use App\payment\service\PaymentRouter;
use App\payment\service\channels\StripeChannel;
use App\payment\model\PaymentChannel;
use App\payment\model\PaymentTransaction;
use App\order\model\Order;
use Common\hashid\HashidService;
use Common\helper\Response;
use Illuminate\Database\Capsule\Manager as Capsule;
use support\Log;

class PaymentController
{
    public function availableChannels($request, string $id)
    {
        $order = Order::where('user_id', $request->userId)->findOrFail(HashidService::decodeId($id));
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

    public function pay($request, string $id)
    {
        $orderId = HashidService::decodeId($id);
        $channelId = $request->input('channel_id');

        $order = $channel = $transaction = null;

        // 事务只做抢占：锁订单、校验、插入 pending 占位交易后立即提交。
        // Stripe 外部 HTTP 调用放到事务外，避免持行锁期间等待网络。
        $denied = Capsule::transaction(function () use ($request, $orderId, $channelId, &$order, &$channel, &$transaction) {
            $order = Order::where('user_id', $request->userId)
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($order->status !== 'pending') {
                return json(Response::error(422, 'Order cannot be paid'));
            }

            $channel = PaymentChannel::findOrFail($channelId);
            // 与列表接口同一套约束：停用/隐藏/不支持币种/金额区间外/区域不符的通道不能直接发起支付
            $availableIds = array_column((new PaymentRouter())->getAvailableChannels([
                'amount'   => $order->total,
                'currency' => $order->currency,
                'region'   => 'global',
            ]), 'channel_id');
            if (!in_array((int) $channel->id, $availableIds, true)) {
                return json(Response::error(422, 'Payment channel is not available for this order'));
            }
            if ($channel->code !== 'stripe') {
                return json(Response::error(422, 'Unsupported payment channel'));
            }

            // 已有待支付/已成功的交易则拒绝重复发起；failed/cancelled 可重试
            $exists = PaymentTransaction::where('order_id', $order->id)
                ->whereIn('status', ['pending', 'success'])
                ->exists();
            if ($exists) {
                return json(Response::error(422, 'Payment already initiated for this order'));
            }

            // 占位 transaction_no 只用于本次 claim，Stripe 成功后替换为 intent id
            $transaction = PaymentTransaction::create([
                'order_id'       => $order->id,
                'user_id'        => $order->user_id,
                'channel_id'     => $channel->id,
                'amount'         => $order->total,
                'currency'       => $order->currency,
                'exchange_rate'  => $order->exchange_rate,
                'transaction_no' => 'pending:' . $order->id . ':' . bin2hex(random_bytes(8)),
                'status'         => 'pending',
            ]);

            return null;
        });

        if ($denied !== null) {
            return $denied;
        }

        try {
            $result = $this->makeStripeChannel($channel)->createPaymentIntent(
                $order,
                $transaction,
                'order_' . $order->id
            );
            return json(Response::success($result));
        } catch (\Throwable $e) {
            $transaction->update(['status' => 'failed']);
            Log::error("Stripe PaymentIntent failed: order={$order->id} txn={$transaction->id}: {$e->getMessage()}");
            return json(Response::error(500, 'Payment initialization failed'));
        }
    }

    protected function makeStripeChannel(PaymentChannel $channel): StripeChannel
    {
        return new StripeChannel($channel);
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
