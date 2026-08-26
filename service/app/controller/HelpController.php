<?php
namespace App\controller;

use App\model\HelpArticle;
use Common\helper\CacheService;
use Common\helper\Response;

class HelpController
{
    public function index($request)
    {
        $locale   = $request->header('Accept-Language', 'en-US');
        $category = $request->input('category');
        $page     = (int)$request->input('page', 1);
        $cacheKey = 'help:list:' . md5("{$locale}:{$category}:{$page}");

        $data = CacheService::remember($cacheKey, CacheService::TTL_HELP_ARTICLES, function () use ($locale, $category, $page) {
            $query = HelpArticle::published()->byLocale($locale);
            if ($category) {
                $query->byCategory($category);
            }
            $articles = $query->orderBy('sort')->orderBy('created_at', 'desc')->paginate(20, ['*'], 'page', $page);
            return [
                'items' => $articles->items(),
                'total' => $articles->total(),
            ];
        });

        return json(Response::paginated($data['items'], $data['total'], $page, 20));
    }

    public function show($request, string $slug)
    {
        $locale   = $request->header('Accept-Language', 'en-US');
        $cacheKey = "help:article:{$locale}:{$slug}";

        $article = CacheService::remember($cacheKey, CacheService::TTL_HELP_ARTICLES, function () use ($locale, $slug) {
            return HelpArticle::published()->byLocale($locale)->where('slug', $slug)->firstOrFail()->toArray();
        });

        return json(Response::success($article));
    }

    public function categories()
    {
        $categories = CacheService::remember('help:categories', CacheService::TTL_HELP_ARTICLES, function () {
            return HelpArticle::published()
                ->select('category')
                ->distinct()
                ->pluck('category')
                ->toArray();
        });

        return json(Response::success($categories));
    }
}
