<?php
namespace App\Admin\Controller;

use App\Supplier\Model\SupplierRating;
use App\Supplier\Service\SupplierRatingService;
use Common\Helper\Response;

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
