<?php
namespace App\product\service;

use App\product\model\Product;
use App\product\model\Region;
use Common\helper\CacheService;
use Common\helper\Response;

class ProductService
{
    public function list(array $filters, int $page = 1, int $pageSize = 20): array
    {
        $cacheKey = 'products:list:' . md5(serialize($filters)) . ":p{$page}:s{$pageSize}";

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

    public function search(string $keyword, int $page = 1, int $perPage = 20): array
    {
        $ids = $this->searchKeywordIds($keyword);
        $query = Product::whereIn('id', $ids);

        $total = $query->count();
        $items = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Response::paginated($items, $total, $page, $perPage);
    }

    /**
     * 返回数组而非模型：CacheService 命中时是 json_decode 结果，返回类型必须一致，
     * 否则第二次请求会因类型不符抛 TypeError。
     */
    public function detail(int $id): array
    {
        // v2：AUD-04 修复前缓存含 User/UserProfile PII，换 key 使旧缓存自然失效
        return CacheService::remember("products:detail:v2:{$id}", CacheService::TTL_PRODUCT_DETAIL, function () use ($id) {
            $product = Product::published()
                ->with(['category', 'skus.regionPrices', 'images', 'reviews.profile'])
                ->findOrFail($id);
            // AUD-04：公开评价只输出评价者白名单字段，禁止序列化 User（email/phone 等会被解密成明文）
            $data = $product->toArray();
            foreach ($product->reviews as $index => $review) {
                $data['reviews'][$index]['user'] = $review->publicUser();
                unset($data['reviews'][$index]['profile']);
            }
            return $data;
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
