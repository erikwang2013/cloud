<?php
namespace App\Admin\Controller;

use App\Product\Model\Product;
use App\Product\Model\ProductSku;
use App\Product\Model\ProductRegion;
use Common\Helper\Response;

class ImportExportController
{
    public function exportProducts()
    {
        $products = Product::with(['category', 'skus.regionPrices', 'images'])->get();

        $rows = [];
        foreach ($products as $p) {
            foreach ($p->skus as $sku) {
                foreach ($sku->regionPrices as $rp) {
                    $rows[] = [
                        $p->id, $p->name, $p->category->name ?? '',
                        $sku->id, json_encode($sku->specs), $sku->cycle,
                        $rp->region_id, $rp->price, $rp->original_price, $rp->stock,
                    ];
                }
            }
        }

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['ProductID', 'Name', 'Category', 'SkuID', 'Specs', 'Cycle', 'RegionID', 'Price', 'OriginalPrice', 'Stock']);
        foreach ($rows as $row) fputcsv($csv, $row);
        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return response($content, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="products_export_' . date('Ymd') . '.csv"',
        ]);
    }

    public function importProducts($request)
    {
        $file = $request->file('file');
        if (!$file) return json(Response::error(422, 'CSV file required'));

        $csv    = fopen($file->getPathname(), 'r');
        $header = fgetcsv($csv);
        $imported = 0;
        $errors   = [];

        while ($row = fgetcsv($csv)) {
            try {
                $data = array_combine($header, array_slice(array_pad($row, count($header), ''), 0, count($header)));
                $productId = (int) $data['ProductID'];
                $skuId     = (int) $data['SkuID'];

                // Upsert product
                $product = $productId ? Product::find($productId) : null;
                if (!$product) {
                    $product = Product::create(['name' => $data['Name'], 'status' => 'published']);
                }

                // Upsert SKU
                $sku = $skuId ? ProductSku::find($skuId) : null;
                $specs = json_decode($data['Specs'] ?? '{}', true) ?: [];
                if ($sku) {
                    $sku->update(['specs' => $specs, 'cycle' => $data['Cycle'] ?? 'monthly']);
                } else {
                    $sku = ProductSku::create([
                        'product_id' => $product->id,
                        'specs'      => $specs,
                        'cycle'      => $data['Cycle'] ?? 'monthly',
                    ]);
                }

                // Upsert region price
                ProductRegion::updateOrCreate(
                    ['sku_id' => $sku->id, 'region_id' => (int) ($data['RegionID'] ?? 0)],
                    ['price' => (float) ($data['Price'] ?? 0), 'original_price' => (float) ($data['OriginalPrice'] ?? 0), 'stock' => (int) ($data['Stock'] ?? 0)]
                );

                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$imported}: " . $e->getMessage();
            }
        }
        fclose($csv);

        return json(Response::success(compact('imported', 'errors')));
    }
}
