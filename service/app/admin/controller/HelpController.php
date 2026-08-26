<?php
namespace App\admin\controller;

use App\model\HelpArticle;
use Common\helper\CacheService;
use Common\helper\Response;

class HelpController
{
    public function index()
    {
        $articles = HelpArticle::orderBy('category')->orderBy('sort')->paginate(30);
        return json(Response::paginated($articles->items(), $articles->total(), (int) request()->input('page', 1), 30));
    }

    public function store($request)
    {
        $data = $request->only(['category', 'title', 'slug', 'content', 'locale', 'sort']);
        $data['status'] = $request->input('status', 'published');
        $article = HelpArticle::create($data);
        CacheService::forgetPattern('help:*');
        return json(Response::success($article));
    }

    public function update($request, int $id)
    {
        $article = HelpArticle::findOrFail($id);
        $article->update($request->only(['category', 'title', 'slug', 'content', 'locale', 'sort', 'status']));
        CacheService::forgetPattern('help:*');
        return json(Response::success($article));
    }

    public function destroy(int $id)
    {
        HelpArticle::findOrFail($id)->update(['status' => 'archived']);
        CacheService::forgetPattern('help:*');
        return json(Response::success());
    }
}
