<?php
namespace App\Product\Controller;

use App\Product\Model\ProductReview;
use App\Product\Model\ReviewStatus;
use Common\Helper\Response;

class ReviewController
{
    public function index($request, int $productId)
    {
        $approved = ReviewStatus::Approved->value;
        $reviews = ProductReview::with('user')
            ->where('product_id', $productId)
            ->where('status', $approved)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $summary = [
            'avg_rating'    => round(ProductReview::where('product_id', $productId)->where('status', $approved)->avg('rating') ?? 0, 1),
            'total_reviews' => ProductReview::where('product_id', $productId)->where('status', $approved)->count(),
            'rating_dist'   => [
                5 => ProductReview::where('product_id', $productId)->where('status', $approved)->where('rating', 5)->count(),
                4 => ProductReview::where('product_id', $productId)->where('status', $approved)->where('rating', 4)->count(),
                3 => ProductReview::where('product_id', $productId)->where('status', $approved)->where('rating', 3)->count(),
                2 => ProductReview::where('product_id', $productId)->where('status', $approved)->where('rating', 2)->count(),
                1 => ProductReview::where('product_id', $productId)->where('status', $approved)->where('rating', 1)->count(),
            ],
        ];

        return json(Response::success($reviews->items(), 'ok', [
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
