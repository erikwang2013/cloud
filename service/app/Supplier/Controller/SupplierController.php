<?php
namespace App\Supplier\Controller;

use App\Supplier\Model\Supplier;
use App\Supplier\Model\SupplierSettlement;
use App\Supplier\Service\SupplierService;
use Common\Helper\Response;

class SupplierController
{
    private SupplierService $service;

    public function __construct()
    {
        $this->service = new SupplierService();
    }

    public function apply($request)
    {
        try {
            $supplier = $this->service->apply($request->userId, $request->all());
            return json(Response::success($supplier));
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(422, $e->getMessage()));
        }
    }

    public function settlements($request)
    {
        $supplier = Supplier::where('user_id', $request->userId)->firstOrFail();
        $settlements = SupplierSettlement::where('supplier_id', $supplier->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return json(Response::success($settlements));
    }

    public function withdraw($request)
    {
        try {
            $supplier = Supplier::where('user_id', $request->userId)->firstOrFail();
            $this->service->requestWithdraw(
                $supplier->id,
                $request->input('amount'),
                $request->input('account_info', [])
            );
            return json(Response::success());
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(422, $e->getMessage()));
        }
    }
}
