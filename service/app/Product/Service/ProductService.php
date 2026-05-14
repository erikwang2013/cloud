<?php
namespace App\Product\Service;

use App\Product\Model\Product;
use App\Product\Model\Region;
use Common\Helper\Response;

class ProductService
{
    public function list(array $filters, int $page = 1, int $pageSize = 20): array
    {
        $query = Product::published()->with(['category', 'skus.regionPrices']);

        if (!empty($filters['category_id'])) {
            $query->byCategory($filters['category_id']);
        }
        if (!empty($filters['region_id'])) {
            $query->whereHas('skus.regionPrices', function ($q) use ($filters) {
                $q->where('region_id', $filters['region_id']);
            });
        }
        if (!empty($filters['keyword'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereJsonContains('name', $filters['keyword'])
                  ->orWhere('slug', 'like', "%{$filters['keyword']}%");
            });
        }
        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        $total = $query->count();
        $items = $query->skip(($page - 1) * $pageSize)->take($pageSize)->get();

        return Response::paginated($items, $total, $page, $pageSize);
    }

    public function detail(int $id): Product
    {
        return Product::published()
            ->with(['category', 'skus.regionPrices', 'images', 'reviews.user.profile'])
            ->findOrFail($id);
    }

    public function getRegions(): array
    {
        return Region::where('status', 'active')->get()->groupBy('continent')->toArray();
    }
}
