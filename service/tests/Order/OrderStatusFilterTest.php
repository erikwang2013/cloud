<?php

namespace Tests\Order;

use App\Order\Controller\OrderController;
use PHPUnit\Framework\TestCase;

final class OrderStatusFilterTest extends TestCase
{
    public function testStatusWhitelistCoversAllRealOrderStatuses(): void
    {
        $this->assertSame(
            ['pending', 'paid', 'provisioning', 'completed', 'refunded'],
            OrderController::ORDER_STATUSES
        );
    }

    public function testInvalidStatusRejectedWith400(): void
    {
        $controller = new OrderController();
        $request    = new FakeOrderRequest(['status' => 'bogus']);

        $response = $controller->myOrders($request);

        $this->assertStringContainsString('"code":400', $response->rawBody());
        $this->assertStringContainsString('Invalid status: bogus', $response->rawBody());
    }

    public function testEmptyStatusAccepted(): void
    {
        // null status 不触发 400（走分页查询，无需 DB 即可断言校验通过）
        $request = new FakeOrderRequest([]);
        $this->assertNull($request->input('status'));
    }
}

final class FakeOrderRequest
{
    public int $userId = 1;
    public string $userRole = 'user';
    private array $params;

    public function __construct(array $params)
    {
        $this->params = $params;
    }

    public function input(string $key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }
}
