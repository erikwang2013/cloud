<?php

namespace Tests\order;

use App\order\service\OrderService;
use PHPUnit\Framework\TestCase;

final class OrderServiceTest extends TestCase
{
    // addToCart validates quantity before any DB access, so the
    // rejection paths are testable without a database connection.

    public function testAddToCartRejectsZeroQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity must be an integer between 1 and 999');
        (new OrderService())->addToCart(1, ['sku_id' => 10, 'quantity' => 0]);
    }

    public function testAddToCartRejectsNegativeQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OrderService())->addToCart(1, ['sku_id' => 10, 'quantity' => -3]);
    }

    public function testAddToCartRejectsQuantityAboveMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OrderService())->addToCart(1, ['sku_id' => 10, 'quantity' => 1000]);
    }

    public function testAddToCartRejectsNonNumericQuantity(): void
    {
        // (int)'abc' === 0 → falls into the invalid range
        $this->expectException(\InvalidArgumentException::class);
        (new OrderService())->addToCart(1, ['sku_id' => 10, 'quantity' => 'abc']);
    }
}
