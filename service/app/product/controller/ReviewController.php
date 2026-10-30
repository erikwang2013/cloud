<?php
namespace App\product\controller;

use App\product\model\ProductReview;
use App\product\model\ReviewStatus;
use Common\hashid\HashidService;
use Common\helper\Response;

class ReviewController
{
    public function index($request, string $productId)
    {
        $productId = HashidService::decodeId($productId);
        $approved = ReviewStatus::Approved->value;
        $reviews = ProductReview::with('profile')
            ->where('product_id', $productId)
            ->where('status', $approved)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // 公开评价只输出评价者白名单字段，禁止序列化 User（email/phone 等会被解密成明文）
        $items = [];
        foreach ($reviews->items() as $review) {
            $row = $review->toArray();
            $row['user'] = $review->publicUser();
            unset($row['profile']);
            $items[] = $row;
        }

        // 一条分组查询替代 8 条 count/avg
        $counts = ProductReview::where('product_id', $productId)
            ->where('status', $approved)
            ->selectRaw('rating, COUNT(*) as c')
            ->groupBy('rating')
            ->pluck('c', 'rating');

        $ratingDist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $total = 0;
        $sum = 0;
        foreach ($counts as $rating => $count) {
            $rating = (int) $rating;
            $count = (int) $count;
            $total += $count;
            $sum += $rating * $count;
            if (isset($ratingDist[$rating])) {
                $ratingDist[$rating] = $count;
            }
        }

        $summary = [
            'avg_rating'    => $total > 0 ? round($sum / $total, 1) : 0,
            'total_reviews' => $total,
            'rating_dist'   => $ratingDist,
        ];

        return json(Response::success($items, 'ok', [
            'page'      => $request->input('page', 1),
            'page_size' => 20,
            'total'     => $reviews->total(),
            'summary'   => $summary,
        ]));
    }

    public function store($request)
    {
        $data = $request->only(['product_id', 'order_id', 'rating', 'content']);
        $data['user_id'] = $request->userId;
        $data['status']  = ReviewStatus::Pending->value;

        $review = ProductReview::create($data);
        return json(Response::success($review));
    }
}
