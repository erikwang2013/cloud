<?php
namespace App\Order\Controller;

use App\Order\Service\OrderService;
use App\Order\Service\CartService;
use App\Order\Model\Order;
use Common\Helper\Response;

class OrderController
{
    private OrderService $orderService;
    private CartService $cartService;

    public function __construct()
    {
        $this->orderService = new OrderService();
        $this->cartService  = new CartService();
    }

    public function addToCart($request)
    {
        $data = $request->only(['sku_id', 'region_id', 'quantity', 'cycle']);
        $this->orderService->addToCart($request->userId, $data);
        return json(Response::success(null, 'Added to cart'));
    }

    public function cart($request)
    {
        $items = $this->cartService->getCart($request->userId);
        return json(Response::success($items));
    }

    public function store($request)
    {
        $cartIds  = $request->input('cart_ids', []);
        $currency = $request->input('currency', 'USD');

        try {
            $order = $this->orderService->createFromCart($request->userId, $cartIds, $currency);
            return json(Response::success($order, 'Order created'));
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(422, $e->getMessage()));
        }
    }

    public function myOrders($request)
    {
        $page     = (int)$request->input('page', 1);
        $pageSize = (int)$request->input('page_size', 10);

        $orders = Order::where('user_id', $request->userId)
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);

        return json(Response::paginated(
            $orders->items(),
            $orders->total(),
            $page,
            $pageSize
        ));
    }

    public function show($request, int $id)
    {
        $order = Order::with(['items', 'timeline'])
            ->where('user_id', $request->userId)
            ->findOrFail($id);

        return json(Response::success($order));
    }
}
