<?php
namespace App\Admin\Controller;

use App\Cron\PaymentReconcile;
use App\Payment\Model\PaymentChannel;
use App\Payment\Model\PaymentTransaction;
use Common\Helper\Response;
use Common\Security\AuditLogger;

class PaymentController
{
    public function channels()
    {
        // 列裁剪：不加载 api_key_encrypted / webhook_secret，避免 Encryptable 解密后明文泄漏
        $channels = PaymentChannel::orderBy('name')->get([
            'id', 'name', 'code', 'currency_support', 'fee_config', 'is_visible',
            'visible_regions', 'min_amount', 'max_amount', 'status',
        ]);
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
        // 按日期返回对账记录（默认今天）；status=unverified 表示真实通道对账未完成
        $date = $request->input('date', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !\DateTime::createFromFormat('!Y-m-d', $date)) {
            return json(Response::error(400, 'Invalid date, expected Y-m-d'));
        }

        $pending = PaymentTransaction::where('status', 'pending')
            ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->count();

        $failed = PaymentTransaction::where('status', 'failed')
            ->whereDate('created_at', date('Y-m-d'))
            ->count();

        $records = \Illuminate\Database\Capsule\Manager::table('payment_reconcile')
            ->where('date', $date)
            ->orderBy('channel_id')
            ->get();

        return json(Response::success([
            'stale_pending'    => $pending,
            'failed_today'     => $failed,
            'reconcile_date'   => $date,
            'records'          => $records,
            'verified_count'   => $records->where('status', 'verified')->count(),
            'mismatch_count'   => $records->where('status', 'mismatch')->count(),
            'unverified_count' => $records->where('status', 'unverified')->count(),
        ]));
    }

    public function reconcileRun($request)
    {
        // 触发按日对账（会调用通道报表 API，可能较慢）
        $date = $request->input('date', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !\DateTime::createFromFormat('!Y-m-d', $date)) {
            return json(Response::error(400, 'Invalid date, expected Y-m-d'));
        }

        try {
            (new PaymentReconcile())->run($date);
        } catch (\Throwable $e) {
            AuditLogger::record('payment_reconcile_run', [
                'user_id' => $request->userId,
                'input'   => ['date' => $date, 'error' => $e->getMessage()],
                'status'  => 'failed',
            ], $request);
            return json(Response::error(500, 'Reconcile failed'));
        }

        AuditLogger::record('payment_reconcile_run', [
            'user_id' => $request->userId,
            'input'   => ['date' => $date],
        ], $request);

        return json(Response::success(['reconcile_date' => $date]));
    }
}
