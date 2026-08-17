<?php
namespace App\Admin\Controller;

use App\Product\Model\Product;
use App\Product\Model\ProductSku;
use App\Product\Model\ProductRegion;
use Common\Helper\Response;
use Common\Money\Money;
use Illuminate\Database\Capsule\Manager as Capsule;

class ImportExportController
{
    // 防 CSV 公式注入：以 = + - @ 开头的单元格加单引号前缀
    private static function csvSafe(mixed $value): string
    {
        $value = (string) $value;
        if ($value !== '' && str_contains('=+-@', $value[0])) {
            return "'" . $value;
        }
        return $value;
    }

    public function exportProducts()
    {
        $products = Product::with(['category', 'skus.regionPrices', 'images'])->get();

        $rows = [];
        foreach ($products as $p) {
            foreach ($p->skus as $sku) {
                foreach ($sku->regionPrices as $rp) {
                    $rows[] = [
                        self::csvSafe($p->id), self::csvSafe($p->name), self::csvSafe($p->category->name ?? ''),
                        self::csvSafe($sku->id), self::csvSafe(json_encode($sku->specs)), self::csvSafe($sku->cycle),
                        self::csvSafe($rp->region_id), self::csvSafe($rp->price), self::csvSafe($rp->original_price), self::csvSafe($rp->stock),
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

        // 单个事务批量导入：大幅减少行级提交开销，失败行仍按行跳过并记录
        Capsule::transaction(function () use ($csv, $header, &$imported, &$errors) {
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

                    // Upsert region price（D4：价格字符串 bcmath 路径，写 DECIMAL(14,4) 前 bcround；空串/0 归一为 '0'）
                    ProductRegion::updateOrCreate(
                        ['sku_id' => $sku->id, 'region_id' => (int) ($data['RegionID'] ?? 0)],
                        [
                            'price'          => Money::bcround((string) ($data['Price'] ?: 0), 4),
                            'original_price' => Money::bcround((string) ($data['OriginalPrice'] ?: 0), 4),
                            'stock'          => (int) ($data['Stock'] ?? 0),
                        ]
                    );

                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "Row {$imported}: " . $e->getMessage();
                }
            }
        });
        fclose($csv);

        return json(Response::success(compact('imported', 'errors')));
    }
}
