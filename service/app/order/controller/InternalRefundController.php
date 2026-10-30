<?php
namespace App\order\controller;

use App\order\model\Order;
use App\order\model\Refund;
use App\order\service\RefundService;
use Common\helper\Response;

/**
 * 内部退款执行端点：admin 审批退款时经 InternalTokenMiddleware 调用。
 * 参数为数据库数字 ID（非 hashid）。
 */
class InternalRefundController
{
    public function execute($request)
    {
        $refundId = filter_var($request->post('refund_id'), FILTER_VALIDATE_INT);
        $operatorId = filter_var($request->post('operator_id'), FILTER_VALIDATE_INT);
        if ($refundId === false || $refundId <= 0 || $operatorId === false || $operatorId <= 0) {
            return json(Response::error(400, 'Invalid parameters'));
        }

        $refund = Refund::find($refundId);
        if (!$refund) {
            return json(Response::error(404, 'Refund not found'));
        }
        if (!Order::where('id', $refund->order_id)->exists()) {
            return json(Response::error(404, 'Order not found'));
        }

        try {
            $refund = (new RefundService())->executePending($refund, $operatorId);
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(409, $e->getMessage()));
        } catch (\RuntimeException $e) {
            return json(Response::error(502, $e->getMessage()));
        }

        return json(Response::success(['status' => $refund->status]));
    }
}
