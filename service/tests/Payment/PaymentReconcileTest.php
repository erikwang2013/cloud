<?php

namespace Tests\Payment;

use App\Cron\PaymentReconcile;
use App\Payment\Service\Channels\StripeChannel;
use PHPUnit\Framework\TestCase;

final class PaymentReconcileTest extends TestCase
{
    public function testCompareVerifiedWhenAllCurrenciesMatch(): void
    {
        $result = PaymentReconcile::compare(['USD' => '19.9900'], ['USD' => '19.9900']);
        $this->assertSame('verified', $result['status']);
        $this->assertSame('0', $result['diff']);
    }

    public function testCompareVerifiedMultiCurrency(): void
    {
        $result = PaymentReconcile::compare(
            ['USD' => '10.0000', 'EUR' => '5.0000'],
            ['USD' => '10.0000', 'EUR' => '5.0000']
        );
        $this->assertSame('verified', $result['status']);
        $this->assertSame('0', $result['diff']);
    }

    public function testCompareMismatchOnAmountDifference(): void
    {
        $result = PaymentReconcile::compare(['USD' => '20.0000'], ['USD' => '19.9900']);
        $this->assertSame('mismatch', $result['status']);
        $this->assertSame('0.0100', $result['diff']);
        $this->assertSame('20.0000', $result['channel_total']);
        $this->assertSame('19.9900', $result['system_total']);
    }

    public function testCompareMismatchOnCurrencySetDifference(): void
    {
        $result = PaymentReconcile::compare(
            ['USD' => '10.0000'],
            ['USD' => '10.0000', 'EUR' => '5.0000']
        );
        $this->assertSame('mismatch', $result['status']);
        $this->assertSame('-5.0000', $result['diff']);
    }

    public function testCompareBothEmptyIsVerified(): void
    {
        $result = PaymentReconcile::compare([], []);
        $this->assertSame('verified', $result['status']);
        $this->assertSame('0', $result['diff']);
    }

    public function testCompareSubCentRoundsToCentsHalfUp(): void
    {
        // 本地 4 位小数金额按分 half-up（与 Webhook toSmallestUnit 同规则）
        $this->assertSame('verified', PaymentReconcile::compare(['USD' => '19.9900'], ['USD' => '19.9940'])['status']);
        $this->assertSame('verified', PaymentReconcile::compare(['USD' => '20.0000'], ['USD' => '19.9950'])['status']);
        $this->assertSame('verified', PaymentReconcile::compare(['USD' => '20.0000'], ['USD' => '19.9960'])['status']);
        $this->assertSame('mismatch', PaymentReconcile::compare(['USD' => '20.0000'], ['USD' => '19.9940'])['status']);
    }

    public function testCompareZeroDecimalCurrencyRoundsToUnit(): void
    {
        $this->assertSame('verified', PaymentReconcile::compare(['JPY' => '1000'], ['JPY' => '1000.0000'])['status']);
        $this->assertSame('mismatch', PaymentReconcile::compare(['JPY' => '1000'], ['JPY' => '1000.6000'])['status']);
    }

    public function testCompareVerifiedForcesZeroDiff(): void
    {
        // 分精度一致但原始汇总存在子分残余时，diff 归 0（verified 语义优先）
        $result = PaymentReconcile::compare(['USD' => '20.0000'], ['USD' => '19.9950']);
        $this->assertSame('verified', $result['status']);
        $this->assertSame('0', $result['diff']);
    }

    public function testSmallestToMajorConversion(): void
    {
        $this->assertSame('19.9900', StripeChannel::smallestToMajor(1999, 'USD'));
        $this->assertSame('0.0000', StripeChannel::smallestToMajor(0, 'USD'));
        $this->assertSame('1000', StripeChannel::smallestToMajor(1000, 'JPY'));
        $this->assertSame('9.9900', StripeChannel::smallestToMajor(999, 'EUR'));
    }

    public function testInvalidDateRejectedBeforeAnyDbAccess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PaymentReconcile())->run('2026-13-99');
    }

    public function testNormalizedDateRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PaymentReconcile())->run('2026-02-30');
    }
}
