<?php
namespace App\Controller;

use App\Model\HelpArticle;
use Common\Helper\Response;

class HelpController
{
    public function index($request)
    {
        $locale   = $request->header('Accept-Language', 'en-US');
        $category = $request->input('category');
        $query    = HelpArticle::published()->byLocale($locale);

        if ($category) {
            $query->byCategory($category);
        }

        $articles = $query->orderBy('sort')->orderBy('created_at', 'desc')->paginate(20);
        return json(Response::paginated($articles->items(), $articles->total(), $request->input('page', 1), 20));
    }

    public function show($request, string $slug)
    {
        $locale  = $request->header('Accept-Language', 'en-US');
        $article = HelpArticle::published()->byLocale($locale)->where('slug', $slug)->firstOrFail();
        return json(Response::success($article));
    }

    public function categories()
    {
        $categories = HelpArticle::published()
            ->select('category')
            ->distinct()
            ->pluck('category');
        return json(Response::success($categories));
    }
}
