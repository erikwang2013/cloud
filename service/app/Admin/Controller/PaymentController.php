<?php
namespace App\Admin\Controller;

use App\Payment\Model\PaymentChannel;
use App\Payment\Model\PaymentTransaction;
use Common\Helper\Response;

class PaymentController
{
    public function channels()
    {
        $channels = PaymentChannel::orderBy('name')->get();
        return json(Response::success($channels));
    }

    public function updateChannel($request, int $id)
    {
        $channel = PaymentChannel::findOrFail($id);
        $channel->update($request->only(['status', 'fee_config', 'min_amount', 'max_amount']));
        return json(Response::success($channel));
    }

    public function transactions($request)
    {
        $query = PaymentTransaction::with(['order', 'channel']);

        if ($status = $request->input('status')) $query->where('status', $status);
        if ($orderId = $request->input('order_id')) $query->where('order_id', $orderId);

        $txns = $query->orderBy('created_at', 'desc')->paginate(30);
        return json(Response::paginated($txns->items(), $txns->total(), $request->input('page', 1), 30));
    }

    public function reconcile($request)
    {
        $pending = PaymentTransaction::where('status', 'pending')
            ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->count();

        $failed = PaymentTransaction::where('status', 'failed')
            ->whereDate('created_at', date('Y-m-d'))
            ->count();

        // 按日期返回对账记录（默认今天）；status=unverified 表示真实通道对账未完成
        $date    = $request->input('date', date('Y-m-d'));
        $records = \Illuminate\Database\Capsule\Manager::table('payment_reconcile')
            ->where('date', $date)
            ->orderBy('channel_id')
            ->get();

        return json(Response::success([
            'stale_pending'    => $pending,
            'failed_today'     => $failed,
            'reconcile_date'   => $date,
            'records'          => $records,
            'unverified_count' => $records->where('status', 'unverified')->count(),
        ]));
    }
}
