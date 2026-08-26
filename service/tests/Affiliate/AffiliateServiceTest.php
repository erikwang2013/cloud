<?php

namespace Tests\Affiliate;

use App\Affiliate\Model\AffiliateEarning;
use App\Affiliate\Service\AffiliateService;
use PHPUnit\Framework\TestCase;

final class AffiliateServiceTest extends TestCase
{
    /**
     * D4 修复回归：佣金 = total × (rate%/100)，全程字符串 bcmath，
     * 禁止浮点（12.55% 等非整百分比在 float 下会失真）。
     * 期望值用独立 bcmath 计算交叉验证后固化。
     */
    public function testEarningAmountUsesBcmath(): void
    {
        $this->assertSame('154.9383', AffiliateService::earningAmount('1234.5679', '12.55'));
    }

    public function testEarningAmountRoundsHalfUpAt4Scale(): void
    {
        $this->assertSame('10.0000', AffiliateService::earningAmount('99.99995', '10'));
        $this->assertSame('100.0000', AffiliateService::earningAmount('1000.00005', '10'));
    }

    public function testEarningAmountZeroRate(): void
    {
        $this->assertSame('0.0000', AffiliateService::earningAmount('500.1234', '0'));
    }

    public function testEarningAmountLargeTotal(): void
    {
        $this->assertSame('35000.0043', AffiliateService::earningAmount('1000000.1235', '3.5'));
    }

    public function testEarningModelMoneyFields(): void
    {
        $fillable = (new AffiliateEarning())->getFillable();
        foreach (['affiliate_id', 'order_id', 'rate', 'amount', 'currency', 'status'] as $field) {
            $this->assertContains($field, $fillable);
        }
    }
}
