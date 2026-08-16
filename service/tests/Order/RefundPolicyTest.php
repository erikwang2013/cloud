<?php

namespace Tests\Order;

use App\Order\Service\RefundService;
use PHPUnit\Framework\TestCase;

final class RefundPolicyTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testServerRefundableWithin72Hours(): void
    {
        $this->assertNull(RefundService::windowError('server', self::NOW - 71 * 3600, self::NOW));
    }

    public function testServerExpiredAfter72Hours(): void
    {
        $error = RefundService::windowError('server', self::NOW - 73 * 3600, self::NOW);
        $this->assertStringContainsString('72 hours', $error);
    }

    public function testServerBoundaryExactly72HoursIsRefundable(): void
    {
        $this->assertNull(RefundService::windowError('server', self::NOW - 72 * 3600, self::NOW));
    }

    public function testDomainRefundableWithin5Days(): void
    {
        $this->assertNull(RefundService::windowError('domain', self::NOW - 4 * 86400, self::NOW));
    }

    public function testDomainExpiredAfter5Days(): void
    {
        $error = RefundService::windowError('domain', self::NOW - 6 * 86400, self::NOW);
        $this->assertStringContainsString('5 days', $error);
    }

    public function testIpNeverRefundable(): void
    {
        $error = RefundService::windowError('ip', self::NOW - 3600, self::NOW);
        $this->assertStringContainsString('not refundable', $error);
    }

    public function testUnknownOrNullTypeHasNoWindow(): void
    {
        $this->assertNull(RefundService::windowError('disk', self::NOW - 30 * 86400, self::NOW));
        $this->assertNull(RefundService::windowError(null, self::NOW - 30 * 86400, self::NOW));
    }

    public function testToSmallestUnitRoundsHalfUpWithoutFloat(): void
    {
        $service = new RefundService();
        $this->assertSame(1250, $service->toSmallestUnit('12.50', 'USD'));
        $this->assertSame(1234, $service->toSmallestUnit('12.3449', 'USD'));
        $this->assertSame(1235, $service->toSmallestUnit('12.345', 'USD'));
        $this->assertSame(10, $service->toSmallestUnit('9.99', 'JPY'));
    }
}
