<?php
namespace App\Admin\Controller;

use App\Order\Model\Order;
use App\Order\Model\OrderTimeline;
use App\Order\Model\Refund;
use Common\Helper\Response;

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

    public function show(int $id)
    {
        $order = Order::with(['items', 'timeline', 'transactions', 'resources'])->findOrFail($id);
        return json(Response::success($order));
    }

    public function refund($request, int $id)
    {
        $order = Order::findOrFail($id);
        if (!in_array($order->status, ['paid', 'completed'])) {
            return json(Response::error(422, 'Order cannot be refunded'));
        }

        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id'  => $order->user_id,
            'amount'   => $request->input('amount'),
            'reason'   => $request->input('reason'),
            'status'   => 'pending',
        ]);

        OrderTimeline::create([
            'order_id' => $order->id,
            'status'   => 'refunding',
            'operator' => "admin:{$request->userId}",
            'remark'   => "Refund requested: {$request->input('amount')} {$order->currency}",
        ]);

        return json(Response::success($refund, 'Refund request submitted'));
    }
}
