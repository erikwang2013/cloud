<?php

namespace Tests\order;

use App\order\service\CartService;
use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    public function testValidQuantitiesPass(): void
    {
        $this->assertSame(1, CartService::normalizeQuantity(1));
        $this->assertSame(5, CartService::normalizeQuantity('5'));
        $this->assertSame(999, CartService::normalizeQuantity(999));
    }

    public function testZeroIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CartService::normalizeQuantity(0);
    }

    public function testNegativeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CartService::normalizeQuantity(-3);
    }

    public function testAboveUpperLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CartService::normalizeQuantity(1000);
    }

    public function testNonNumericIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CartService::normalizeQuantity('abc');
    }

    public function testTrailingGarbageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CartService::normalizeQuantity('5abc');
    }

    public function testFloatIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CartService::normalizeQuantity(5.5);
    }

    public function testMissingQuantityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CartService::normalizeQuantity(null);
    }
}
