<?php
namespace App\Product\Controller;

use App\Product\Service\ProductService;
use Common\Helper\Response;

class ProductController
{
    private ProductService $service;

    public function __construct()
    {
        $this->service = new ProductService();
    }

    public function index($request)
    {
        $filters  = $request->only(['category_id', 'region_id', 'keyword', 'supplier_id']);
        $page     = (int)$request->input('page', 1);
        $pageSize = min((int)$request->input('page_size', 20), 50);

        $result = $this->service->list($filters, $page, $pageSize);
        return json($result);
    }

    public function show($request, int $id)
    {
        $product = $this->service->detail($id);
        return json(Response::success($product));
    }

    public function regions()
    {
        $regions = $this->service->getRegions();
        return json(Response::success($regions));
    }
}
