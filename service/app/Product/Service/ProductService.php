<?php
namespace App\Product\Service;

use App\Product\Model\Product;
use App\Product\Model\Region;
use Common\Helper\CacheService;
use Common\Helper\Response;

class ProductService
{
    public function list(array $filters, int $page = 1, int $pageSize = 20): array
    {
        $cacheKey = 'products:list:' . md5(serialize($filters)) . ":p{$page}";

        return CacheService::remember($cacheKey, CacheService::TTL_PRODUCT_LIST, function () use ($filters, $page, $pageSize) {
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
                $ids = $this->searchKeywordIds($filters['keyword']);
                $query->whereIn('id', $ids);
            }
            if (!empty($filters['supplier_id'])) {
                $query->where('supplier_id', $filters['supplier_id']);
            }

            $total = $query->count();
            $items = $query->skip(($page - 1) * $pageSize)->take($pageSize)->get();

            return Response::paginated($items, $total, $page, $pageSize);
        });
    }

    public function detail(int $id): Product
    {
        return CacheService::remember("products:detail:{$id}", CacheService::TTL_PRODUCT_DETAIL, function () use ($id) {
            return Product::published()
                ->with(['category', 'skus.regionPrices', 'images', 'reviews.user.profile'])
                ->findOrFail($id);
        });
    }

    public function getRegions(): array
    {
        return CacheService::remember('regions:all', CacheService::TTL_REGIONS, function () {
            return Region::where('status', 'active')->get()->groupBy('continent')->toArray();
        });
    }

    public static function invalidateCache(): void
    {
        CacheService::forgetPattern('products:*');
    }

    /**
     * ES 主路径搜索商品 id；ES 不可用时降级 SQL 模糊匹配，避免搜索接口因 ES 故障 500。
     * ponytail: 结果截断 200 条；数据量超过时需改游标分页。
     */
    private function searchKeywordIds(string $keyword): array
    {
        try {
            return Product::search(self::escapeQueryString($keyword))->take(200)->keys()->all();
        } catch (\Throwable) {
            return Product::whereJsonContains('name', $keyword)
                ->orWhere('slug', 'like', "%{$keyword}%")
                ->pluck('id')->all();
        }
    }

    /**
     * 转义 ES query_string 保留字（引擎拼接 `*{$query}*` 无转义，特殊字符会抛解析异常）。
     */
    public static function escapeQueryString(string $keyword): string
    {
        return preg_replace('/([+\-=&|><!(){}[\]^"~*?:\\\\\/])/', '\\\\$1', $keyword);
    }
}
