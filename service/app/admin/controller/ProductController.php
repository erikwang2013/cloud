<?php
namespace App\admin\controller;

use App\product\model\Product;
use App\product\model\ProductSku;
use App\product\model\ProductRegion;
use Common\helper\Response;

class ProductController
{
    public function store($request)
    {
        $product = Product::create($request->all());
        \App\product\service\ProductService::invalidateCache();
        return json(Response::success($product, 'Product created'));
    }

    public function update($request, int $id)
    {
        $product = Product::findOrFail($id);
        $product->update($request->all());
        \Common\helper\CacheService::forget("products:detail:{$id}");
        \Common\helper\CacheService::forgetPattern('products:list:*');
        return json(Response::success($product));
    }

    public function destroy(int $id)
    {
        Product::findOrFail($id)->delete();
        \App\product\service\ProductService::invalidateCache();
        return json(Response::success(null, 'Product deleted'));
    }

    public function storeSku($request, int $productId)
    {
        $sku = ProductSku::create(array_merge($request->all(), ['product_id' => $productId]));
        \Common\helper\CacheService::forget("products:detail:{$productId}");
        \Common\helper\CacheService::forgetPattern('products:list:*');
        return json(Response::success($sku, 'SKU created'));
    }

    public function updateSku($request, int $id)
    {
        $sku = ProductSku::findOrFail($id);
        $sku->update($request->all());
        \Common\helper\CacheService::forget("products:detail:{$sku->product_id}");
        \Common\helper\CacheService::forgetPattern('products:list:*');
        return json(Response::success($sku));
    }

    public function setRegionPrice($request, int $skuId)
    {
        $regionPrice = ProductRegion::updateOrCreate(
            ['sku_id' => $skuId, 'region_id' => $request->input('region_id')],
            [
                'price'            => $request->input('price'),
                'original_price'   => $request->input('original_price'),
                'setup_fee'        => $request->input('setup_fee', '0'),
                'discount_percent' => $request->input('discount_percent'),
            ]
        );
        $sku = ProductSku::find($skuId);
        if ($sku) {
            \Common\helper\CacheService::forget("products:detail:{$sku->product_id}");
            \Common\helper\CacheService::forgetPattern('products:list:*');
        }
        return json(Response::success($regionPrice, 'Region price set'));
    }
}
