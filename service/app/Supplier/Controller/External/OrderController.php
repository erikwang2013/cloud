<?php
namespace App\Supplier\Controller\External;

use App\Order\Model\Order;
use App\Supplier\Model\Supplier;
use Common\Helper\Response;

class OrderController
{
    public function index($request)
    {
        $supplier = Supplier::findOrFail($request->supplierId);
        $status   = $request->input('status');

        $query = Order::whereHas('items.sku.product', function ($q) use ($supplier) {
            $q->where('supplier_id', $supplier->id);
        });

        if ($status) {
            $query->where('status', $status);
        }

        $pageSize = min((int)$request->input('page_size', 20), 50);
        $orders   = $query->orderBy('created_at', 'desc')->paginate($pageSize);

        return json(Response::paginated(
            $orders->items(),
            $orders->total(),
            (int)$request->input('page', 1),
            $pageSize
        ));
    }

    public function show($request, int $id)
    {
        $supplier = Supplier::findOrFail($request->supplierId);
        $order    = Order::whereHas('items.sku.product', function ($q) use ($supplier) {
            $q->where('supplier_id', $supplier->id);
        })->with(['items.sku.product', 'items.sku.regionPrices'])->findOrFail($id);

        return json(Response::success($order));
    }
}
