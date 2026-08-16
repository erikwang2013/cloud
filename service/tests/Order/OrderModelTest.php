<?php

namespace Tests\Order;

use App\Order\Model\Order;
use App\Order\Model\OrderItem;
use App\Order\Model\OrderTimeline;
use PHPUnit\Framework\TestCase;

final class OrderModelTest extends TestCase
{
    public function testOrderUsesSnowflakeId(): void
    {
        $order = new Order();
        $this->assertSame('orders', $order->getTable());
        $this->assertFalse($order->getIncrementing());
        $this->assertSame('int', $order->getKeyType());
    }

    public function testOrderFillableCoversMoneyFields(): void
    {
        $fillable = (new Order())->getFillable();
        foreach (['order_no', 'user_id', 'type', 'status', 'currency', 'subtotal', 'discount', 'tax', 'total', 'exchange_rate', 'paid_at'] as $field) {
            $this->assertContains($field, $fillable);
        }
    }

    public function testOrderRelationsExist(): void
    {
        $order = new Order();
        foreach (['items', 'timeline', 'user', 'transactions', 'resources', 'refund'] as $rel) {
            $this->assertTrue(method_exists($order, $rel), "missing relation: {$rel}");
        }
    }

    public function testOrderItemCastsResourceSnapshotToArray(): void
    {
        $item = new OrderItem();
        $snapshot = ['specs' => ['cpu' => 2], 'region' => 1];
        $item->resource_snapshot = $snapshot;

        $stored = $item->getAttributes()['resource_snapshot'];
        $this->assertIsString($stored);
        $this->assertSame(json_encode($snapshot), $stored);
        $this->assertSame($snapshot, $item->resource_snapshot);
    }

    public function testOrderItemFillableAndTable(): void
    {
        $item = new OrderItem();
        $this->assertSame('order_items', $item->getTable());
        foreach (['order_id', 'sku_id', 'quantity', 'cycle', 'unit_price', 'total_price', 'status'] as $field) {
            $this->assertContains($field, $item->getFillable());
        }
    }

    public function testOrderTimelineFillable(): void
    {
        $tl = new OrderTimeline();
        $this->assertSame('order_timeline', $tl->getTable());
        foreach (['order_id', 'status', 'operator', 'remark'] as $field) {
            $this->assertContains($field, $tl->getFillable());
        }
    }
}
