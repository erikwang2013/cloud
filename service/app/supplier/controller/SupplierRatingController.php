<?php
namespace App\supplier\controller;

use App\supplier\service\SupplierRatingService;
use Common\helper\Response;

class SupplierRatingController
{
    public function store($request)
    {
        $userId = $request->userId;
        $supplierId = $request->input('supplier_id');
        $orderId    = $request->input('order_id');

        if (!$supplierId || !$orderId) {
            return json(Response::error(400, 'supplier_id and order_id are required'));
        }

        try {
            $service = new SupplierRatingService();
            $rating  = $service->rate($userId, (int) $supplierId, (int) $orderId, $request->all());
            return json(Response::success($rating, 'Rating submitted'));
        } catch (\RuntimeException $e) {
            return json(Response::error(400, $e->getMessage()));
        }
    }

    public function myRatings($request)
    {
        $userId = $request->userId;
        $ratings = \App\supplier\model\SupplierRating::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
        return json(Response::success($ratings));
    }

    public function supplierRatings($request, int $supplierId)
    {
        $service = new SupplierRatingService();
        $ratings = $service->listForSupplier($supplierId);
        return json(Response::success($ratings));
    }
}
