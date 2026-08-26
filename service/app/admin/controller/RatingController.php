<?php
namespace App\admin\controller;

use App\supplier\model\SupplierRating;
use App\supplier\service\SupplierRatingService;
use Common\helper\Response;

class RatingController
{
    public function supplierRatings($request, int $id)
    {
        $service = new SupplierRatingService();
        $ratings = $service->listForSupplier($id, 50);
        return json(Response::success($ratings));
    }

    public function approve(int $id)
    {
        $service = new SupplierRatingService();
        $service->approve($id);
        return json(Response::success(null, 'Rating approved'));
    }

    public function hide(int $id)
    {
        $service = new SupplierRatingService();
        $service->hide($id);
        return json(Response::success(null, 'Rating hidden'));
    }
}
