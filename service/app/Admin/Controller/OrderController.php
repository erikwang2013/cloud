<?php
namespace App\Admin\Controller;

use App\Order\Model\Order;
use App\Order\Model\OrderTimeline;
use App\Order\Model\Refund;
use App\Order\Service\RefundService;
use Common\ExcelExport;
use Common\Helper\Response;
use Common\Security\AuditLogger;

class OrderController
{
    public function index($request)
    {
        $query = Order::with(['user.profile', 'items']);

        if ($status = $request->input('status')) $query->where('status', $status);
        if ($type = $request->input('type')) $query->where('type', $type);
        if ($userId = $request->input('user_id')) $query->where('user_id', $userId);
        if ($orderNo = $request->input('order_no')) $query->where('order_no', $orderNo);
        if ($dateStart = $request->input('date_start')) $query->whereDate('created_at', '>=', $dateStart);
        if ($dateEnd = $request->input('date_end')) $query->whereDate('created_at', '<=', $dateEnd);

        $orders = $query->orderBy('created_at', 'desc')->paginate(30);
        return json(Response::paginated($orders->items(), $orders->total(), $request->input('page', 1), 30));
    }

    public function export($request)
    {
        $query = Order::with(['user.profile']);

        if ($status = $request->input('status')) $query->where('status', $status);
        if ($type = $request->input('type')) $query->where('type', $type);
        if ($userId = $request->input('user_id')) $query->where('user_id', $userId);
        if ($orderNo = $request->input('order_no')) $query->where('order_no', $orderNo);
        if ($dateStart = $request->input('date_start')) $query->whereDate('created_at', '>=', $dateStart);
        if ($dateEnd = $request->input('date_end')) $query->whereDate('created_at', '<=', $dateEnd);

        $maxRows = 10000;
        $items = $query->orderBy('created_at', 'desc')->limit($maxRows)->get()->toArray();

        $columns = ['id', 'order_no', 'user_id', 'type', 'status', 'total', 'currency', 'created_at', 'paid_at'];
        $labels = [
            'id' => 'ID', 'order_no' => '订单号', 'user_id' => '用户ID',
            'type' => '类型', 'status' => '状态', 'total' => '金额',
            'currency' => '币种', 'created_at' => '创建时间', 'paid_at' => '支付时间',
        ];

        $path = ExcelExport::export('orders', $columns, $items, $labels);
        return response()->download($path, 'orders_' . date('YmdHis') . '.xlsx');
    }

    public function show(int $id)
    {
        $order = Order::with(['items', 'timeline', 'transactions', 'resources'])->findOrFail($id);
        return json(Response::success($order));
    }

    public function refund($request, int $id)
    {
        $order  = Order::findOrFail($id);
        $amount = (string) $request->input('amount');
        $reason = $request->input('reason');

        // 金额校验：>0 且 ≤ 订单已付金额（bccomp 字符串比较，避免 float 精度误差）
        if (!is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            return json(Response::error(422, 'Refund amount must be greater than 0'));
        }
        if (bccomp($amount, (string) $order->total, 4) > 0) {
            return json(Response::error(422, 'Refund amount cannot exceed order total'));
        }

        // 状态机：仅 paid/completed 订单可退
        if (!in_array($order->status, ['paid', 'completed'])) {
            return json(Response::error(422, 'Order cannot be refunded'));
        }

        // 已有 pending 退款时拒绝重复申请
        if (Refund::where('order_id', $order->id)->where('status', 'pending')->exists()) {
            return json(Response::error(422, 'A refund is already pending for this order'));
        }

        try {
            $refund = (new RefundService())->execute($order, $amount, $reason, $request->userId);
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(422, $e->getMessage()));
        } catch (\RuntimeException $e) {
            return json(Response::error(422, 'Refund failed: ' . $e->getMessage()));
        }

        OrderTimeline::create([
            'order_id' => $order->id,
            'status'   => 'refunding',
            'operator' => "admin:{$request->userId}",
            'remark'   => "Refund requested: {$amount} {$order->currency}",
        ]);

        AuditLogger::record('admin_refund', [
            'user_id' => $request->userId,
            'input'   => [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'amount'   => $amount,
                'currency' => $order->currency,
                'reason'   => $reason,
                'refund_id'=> $refund->id,
                'status'   => $refund->status,
            ],
        ], $request);

        return json(Response::success($refund, 'Refund request submitted'));
    }
}
