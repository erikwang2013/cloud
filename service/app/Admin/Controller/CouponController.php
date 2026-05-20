<?php
namespace App\Admin\Controller;

use App\Order\Model\Coupon;
use Common\Helper\Response;

class CouponController
{
    public function index()
    {
        $coupons = Coupon::orderBy('created_at', 'desc')->paginate(30);
        return json(Response::paginated($coupons->items(), $coupons->total(), (int) request()->input('page', 1), 30));
    }

    public function store($request)
    {
        $coupon = Coupon::create($request->only([
            'code', 'type', 'value', 'min_amount', 'max_discount',
            'max_uses', 'starts_at', 'expires_at',
        ]));
        return json(Response::success($coupon));
    }

    public function destroy(int $id)
    {
        Coupon::findOrFail($id)->update(['status' => 'disabled']);
        return json(Response::success());
    }
}
