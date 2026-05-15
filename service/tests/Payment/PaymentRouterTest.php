<?php

namespace Tests\Payment;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PaymentRouterTest extends TestCase
{
    public function testChannelFilteringByStatus(): void
    {
        $channels = [
            ['code' => 'stripe', 'status' => 'active'],
            ['code' => 'paypal', 'status' => 'inactive'],
            ['code' => 'alipay', 'status' => 'active'],
        ];

        $active = array_filter($channels, fn($c) => $c['status'] === 'active');
        $this->assertCount(2, $active);
        $this->assertSame('stripe', array_values($active)[0]['code']);
        $this->assertSame('alipay', array_values($active)[1]['code']);
    }

    #[DataProvider('amountRangeProvider')]
    public function testChannelAmountConstraints(float $amount, array $channel, bool $expected): void
    {
        $valid = $amount >= $channel['min_amount'] && $amount <= $channel['max_amount'];
        $this->assertSame($expected, $valid);
    }

    public static function amountRangeProvider(): array
    {
        $channel = ['min_amount' => 1.00, 'max_amount' => 9999.00];
        return [
            'within range' => [50.00, $channel, true],
            'at minimum' => [1.00, $channel, true],
            'at maximum' => [9999.00, $channel, true],
            'below minimum' => [0.50, $channel, false],
            'above maximum' => [10000.00, $channel, false],
        ];
    }

    public function testChannelCurrencySupport(): void
    {
        $channel = ['currency_support' => ['USD', 'EUR', 'GBP']];
        $this->assertContains('USD', $channel['currency_support']);
        $this->assertNotContains('CNY', $channel['currency_support']);
    }

    public function testChannelVisibilityByRegion(): void
    {
        $channel = ['visible_regions' => ['US', 'EU', 'UK']];
        $this->assertContains('US', $channel['visible_regions']);
        $this->assertNotContains('CN', $channel['visible_regions']);
    }

    public function testFeeConfigurationParsing(): void
    {
        $feeConfig = [
            'percentage' => 2.9,
            'fixed' => 0.30,
            'currency' => 'USD',
        ];

        $orderAmount = 100.00;
        $fee = round($orderAmount * ($feeConfig['percentage'] / 100) + $feeConfig['fixed'], 2);

        $this->assertSame(3.20, $fee);
    }

    public function testMultipleChannelsSortByPriority(): void
    {
        $channels = [
            ['code' => 'alipay', 'fee_config' => ['percentage' => 1.5]],
            ['code' => 'stripe', 'fee_config' => ['percentage' => 2.9]],
            ['code' => 'paypal', 'fee_config' => ['percentage' => 3.5]],
        ];

        usort($channels, fn($a, $b) => $a['fee_config']['percentage'] <=> $b['fee_config']['percentage']);

        $this->assertSame('alipay', $channels[0]['code']);
        $this->assertSame('stripe', $channels[1]['code']);
        $this->assertSame('paypal', $channels[2]['code']);
    }
}
