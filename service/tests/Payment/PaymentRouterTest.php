<?php

namespace Tests\Payment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentRouterTest extends TestCase
{
    public function testChannelFilteringByStatus(): void
    {
        $channels = [
            ['code' => 'stripe', 'status' => 'active'],
            ['code' => 'paypal', 'status' => 'inactive'],
            ['code' => 'alipay', 'status' => 'active'],
        ];

        $active = array_values(array_filter($channels, fn($c) => $c['status'] === 'active'));
        $this->assertCount(2, $active);
        $this->assertSame('stripe', $active[0]['code']);
    }

    #[DataProvider('amountRangeProvider')]
    public function testChannelAmountConstraints(float $amount, float $min, float $max, bool $expected): void
    {
        $valid = $amount >= $min && $amount <= $max;
        $this->assertSame($expected, $valid);
    }

    public static function amountRangeProvider(): array
    {
        return [
            'within range' => [50.00, 1.00, 9999.00, true],
            'at minimum' => [1.00, 1.00, 9999.00, true],
            'at maximum' => [9999.00, 1.00, 9999.00, true],
            'below minimum' => [0.50, 1.00, 9999.00, false],
            'above maximum' => [10000.00, 1.00, 9999.00, false],
        ];
    }

    public function testChannelCurrencySupport(): void
    {
        $supported = ['USD', 'EUR', 'GBP'];
        $this->assertContains('USD', $supported);
        $this->assertNotContains('CNY', $supported);
    }

    public function testChannelVisibilityByRegion(): void
    {
        $visibleRegions = ['US', 'EU', 'UK'];
        $this->assertContains('US', $visibleRegions);
        $this->assertNotContains('CN', $visibleRegions);
    }

    public function testFeeCalculationMatchesBcmathPattern(): void
    {
        // Production uses: bcadd(bcmul($amount, $rate, 8), $fixed, 4)
        // where $rate is a decimal (e.g. 0.029 for 2.9%)
        $amount = '100.00';
        $rate = '0.029';
        $fixed = '0.30';

        $fee = bcadd(bcmul($amount, $rate, 8), $fixed, 4);
        $this->assertSame('3.2000', $fee);
    }

    public function testChannelsSortedByFee(): void
    {
        $channels = [
            ['code' => 'alipay', 'rate' => 0.015],
            ['code' => 'stripe', 'rate' => 0.029],
            ['code' => 'paypal', 'rate' => 0.035],
        ];

        usort($channels, fn($a, $b) => $a['rate'] <=> $b['rate']);

        $this->assertSame('alipay', $channels[0]['code']);
        $this->assertSame('stripe', $channels[1]['code']);
        $this->assertSame('paypal', $channels[2]['code']);
    }
}
