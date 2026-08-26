<?php
namespace App\order\controller;

use App\order\model\Coupon;
use Common\helper\Response;

class CouponController
{
    public function validate($request)
    {
        $code      = $request->input('code');
        // 边界输入直接以字符串进入 bcmath 链（D4：金额计算禁止 (float)）
        $orderTotal = (string) $request->input('order_total', '0');

        if (empty($code)) {
            return json(Response::error(422, 'Coupon code required'));
        }

        $coupon = Coupon::where('code', $code)->first();
        if (!$coupon) {
            return json(Response::error(404, 'Coupon not found'));
        }

        if (!$coupon->isValid()) {
            return json(Response::error(422, 'Coupon is expired or has reached usage limit'));
        }

        $discount = $coupon->calculateDiscount($orderTotal);

        return json(Response::success([
            'coupon_id' => $coupon->id,
            'code'      => $coupon->code,
            'type'      => $coupon->type,
            'discount'  => $discount,
        ]));
    }
}
