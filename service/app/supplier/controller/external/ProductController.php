<?php
namespace App\supplier\controller\external;

use App\product\model\Product;
use App\supplier\model\SupplierProduct;
use Common\helper\Response;

class ProductController
{
    public function index($request)
    {
        $products = SupplierProduct::where('supplier_id', $request->supplierId)
            ->with('product')
            ->orderBy('created_at', 'desc')
            ->paginate(min((int) $request->input('page_size', 20), 50));

        return json(Response::paginated(
            $products->items(),
            $products->total(),
            (int) $request->input('page', 1),
            $products->perPage()
        ));
    }

    public function store($request)
    {
        $productId = $request->input('product_id');

        $product = Product::find($productId);
        if (!$product) {
            return json(Response::error(422, 'Product not found'));
        }

        $existing = SupplierProduct::where('supplier_id', $request->supplierId)
            ->where('product_id', $productId)
            ->first();
        if ($existing) {
            return json(Response::error(422, 'Product already assigned to this supplier'));
        }

        // 佣金率只能由平台设置：供应商入口忽略请求中的 commission_rate，使用表默认值
        $sp = SupplierProduct::create([
            'supplier_id' => $request->supplierId,
            'product_id'  => $productId,
        ]);

        return json(Response::success($sp));
    }
}
