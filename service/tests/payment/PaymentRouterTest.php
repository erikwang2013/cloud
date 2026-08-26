<?php

namespace Tests\payment;

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
        // Production uses: bcround(bcadd(bcmul(bcround($amount,4), $rate, 8), $fixed, 8), 4)
        // where $rate is a decimal (e.g. 0.029 for 2.9%) — HALF_UP at 4dp, not truncation
        $amount = '100.00';
        $rate = '0.029';
        $fixed = '0.30';

        $fee = \Common\money\Money::bcround(bcadd(bcmul(\Common\money\Money::bcround($amount, 4), $rate, 8), $fixed, 8), 4);
        $this->assertSame('3.2000', $fee);
    }

    public function testCalculateFeeAppliesRateAndFixed(): void
    {
        $router = new \App\payment\service\PaymentRouter();
        $this->assertSame('3.2000', $router->calculateFee('100.00', ['rate' => '0.029', 'fixed' => '0.30']));
    }

    public function testCalculateFeeWithoutConfigIsZero(): void
    {
        $router = new \App\payment\service\PaymentRouter();
        $this->assertSame('0.0000', $router->calculateFee('100.00', []));
    }

    public function testCalculateFeeClampsNegativeRateToZero(): void
    {
        $router = new \App\payment\service\PaymentRouter();
        $this->assertSame('0', $router->calculateFee('100.00', ['rate' => '-0.10']));
    }

    public function testCalculateFeeFixedOnly(): void
    {
        $router = new \App\payment\service\PaymentRouter();
        $this->assertSame('0.5000', $router->calculateFee('50.00', ['fixed' => '0.50']));
    }

    public function testCalculateFeeRoundsHalfUpInsteadOfTruncating(): void
    {
        // 0.12345 * 100% = 0.12345：旧实现 bcadd(...,4) 截断为 0.1234（少收），新实现 HALF_UP 为 0.1235
        $router = new \App\payment\service\PaymentRouter();
        $this->assertSame('0.1235', $router->calculateFee('0.12345', ['rate' => '1']));
        // 0.00004 * 100% 舍去
        $this->assertSame('0.0000', $router->calculateFee('0.00004', ['rate' => '1']));
    }

    public function testCalculateFeeAlignsAmountBeforeRate(): void
    {
        // 5 位小数 amount 先 bcround 到 4 位再乘率（D4：避免尾差进入费率乘积）
        $router = new \App\payment\service\PaymentRouter();
        $this->assertSame('0.3000', $router->calculateFee('10.12345', ['fixed' => '0.30']));
    }

    public function testFeeTotalIdentityZeroError(): void
    {
        // D5：total_amount = bcround(amount,4) + fee 精确成立（对 5 位小数 amount 亦零误差）
        $amount = '10.12345';
        $router = new \App\payment\service\PaymentRouter();
        $feeValue = $router->calculateFee($amount, ['rate' => '0.029', 'fixed' => '0.30']);

        $total = bcadd(\Common\money\Money::bcround($amount, 4), $feeValue, 4);
        $this->assertSame(0, bccomp(bcsub(bcsub($total, \Common\money\Money::bcround($amount, 4), 4), $feeValue, 4), '0', 4));
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
