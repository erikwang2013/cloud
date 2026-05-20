<?php
namespace App\Order\Controller;

use App\Order\Model\Coupon;
use Common\Helper\Response;

class CouponController
{
    public function validate($request)
    {
        $code      = $request->input('code');
        $orderTotal = (float) $request->input('order_total', 0);

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
