<?php

namespace Tests\order;

use App\order\service\CartService;
use PHPUnit\Framework\TestCase;

final class CartServiceTest extends TestCase
{
    // Regression for PUT /api/cart/{id} quantity validation
    // (CartService::normalizeQuantity — pure, DB-free).

    public function testAcceptsValidQuantity(): void
    {
        $this->assertSame(5, CartService::normalizeQuantity('5'));
        $this->assertSame(1, CartService::normalizeQuantity(1));
        $this->assertSame(999, CartService::normalizeQuantity(999));
    }

    public function testRejectsZeroAndNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CartService::normalizeQuantity(0);
        $this->expectException(\InvalidArgumentException::class);
        CartService::normalizeQuantity(-3);
    }

    public function testRejectsAboveMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CartService::normalizeQuantity(1000);
    }

    public function testRejectsNonIntegerInput(): void
    {
        foreach ([null, '', 'abc', 3.5, '2.5', '1e3'] as $bad) {
            try {
                CartService::normalizeQuantity($bad);
                $this->fail("should reject: " . var_export($bad, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
