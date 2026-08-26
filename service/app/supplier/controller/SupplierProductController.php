<?php
namespace App\supplier\controller;

use App\supplier\model\Supplier;
use App\supplier\model\SupplierProduct;
use App\product\model\Product;
use Common\helper\Response;

class SupplierProductController
{
    public function index($request)
    {
        $supplier = Supplier::where('user_id', $request->userId)->firstOrFail();
        $products = SupplierProduct::where('supplier_id', $supplier->id)
            ->with('product')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return json(Response::paginated($products->items(), $products->total(), $request->input('page', 1), 20));
    }

    public function store($request)
    {
        $supplier = Supplier::where('user_id', $request->userId)->firstOrFail();
        $productId = $request->input('product_id');

        $existing = SupplierProduct::where('supplier_id', $supplier->id)->where('product_id', $productId)->first();
        if ($existing) {
            return json(Response::error(422, 'Product already assigned to this supplier'));
        }

        $sp = SupplierProduct::create([
            'supplier_id' => $supplier->id,
            'product_id'  => $productId,
            'commission_rate' => $request->input('commission_rate', 0.1),
        ]);

        return json(Response::success($sp));
    }

    public function destroy($request, int $id)
    {
        $supplier = Supplier::where('user_id', $request->userId)->firstOrFail();
        SupplierProduct::where('id', $id)->where('supplier_id', $supplier->id)->delete();
        return json(Response::success());
    }
}
