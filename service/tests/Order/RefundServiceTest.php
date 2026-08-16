<?php

namespace Tests\Order;

use App\Order\Service\RefundService;
use PHPUnit\Framework\TestCase;

final class RefundServiceTest extends TestCase
{
    // Regression for #1 退款条件校验: refund window rules (pure, DB-free).

    public function testServerRefundableWithin72Hours(): void
    {
        $now = time();
        $this->assertNull(RefundService::windowError('server', $now - 72 * 3600, $now));
        $this->assertNotNull(RefundService::windowError('server', $now - 72 * 3600 - 1, $now));
    }

    public function testDomainRefundableWithin5Days(): void
    {
        $now = time();
        $this->assertNull(RefundService::windowError('domain', $now - 120 * 3600, $now));
        $this->assertNotNull(RefundService::windowError('domain', $now - 120 * 3600 - 1, $now));
    }

    public function testIpTypeIsNeverRefundable(): void
    {
        $now = time();
        $this->assertNotNull(RefundService::windowError('ip', $now, $now));
    }

    public function testUnknownTypeHasNoWindowLimit(): void
    {
        $now = time();
        $this->assertNull(RefundService::windowError('disk', $now - 1000 * 86400, $now));
        $this->assertNull(RefundService::windowError(null, $now - 1000 * 86400, $now));
    }

    public function testWindowErrorIsExactlyAtBoundary(): void
    {
        $now = 1_000_000_000;
        $this->assertSame(
            'Refund window expired: server orders are refundable within 72 hours of payment',
            RefundService::windowError('server', $now - 72 * 3600 - 1, $now)
        );
        $this->assertNull(RefundService::windowError('server', $now - 72 * 3600, $now));
    }

    // --- toSmallestUnit (Stripe amount conversion for refunds) ---

    public function testSmallestUnitForUsd(): void
    {
        $svc = new RefundService();
        $this->assertSame(1999, $svc->toSmallestUnit('19.99', 'USD'));
        $this->assertSame(2000, $svc->toSmallestUnit('19.995', 'USD'));
        $this->assertSame(0, $svc->toSmallestUnit('0', 'USD'));
    }

    public function testSmallestUnitForZeroDecimalCurrency(): void
    {
        $svc = new RefundService();
        $this->assertSame(1000, $svc->toSmallestUnit('1000', 'JPY'));
        $this->assertSame(20, $svc->toSmallestUnit('19.6', 'JPY'));
    }
}
