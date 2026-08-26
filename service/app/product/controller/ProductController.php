<?php
namespace App\product\controller;

use App\product\service\ProductService;
use Common\helper\Response;

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

    public function search($request)
    {
        $keyword = $request->input('q', '');
        $page    = (int) $request->input('page', 1);
        $perPage = min((int) $request->input('page_size', 20), 50);

        if (empty(trim($keyword))) {
            return json(Response::error(422, 'Search query required'));
        }

        return json($this->service->search($keyword, $page, $perPage));
    }
}
