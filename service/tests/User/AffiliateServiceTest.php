<?php

namespace Tests\User;

use App\Affiliate\Service\AffiliateService;
use PHPUnit\Framework\TestCase;

final class AffiliateServiceTest extends TestCase
{
    public function testEarningAmountNonIntegerPercentRate(): void
    {
        // 12.55%：100.0000 × 0.1255 = 12.5500（浮点路径 0.1255 二进制漂移会产出 12.5499...）
        $this->assertSame('12.5500', AffiliateService::earningAmount('100.0000', '12.55'));
    }

    public function testEarningAmountRoundsTo4dp(): void
    {
        $this->assertSame('1.2705', AffiliateService::earningAmount('10.1234', '12.55'));
    }

    public function testEarningAmountCarryAcrossInteger(): void
    {
        $this->assertSame('10.0000', AffiliateService::earningAmount('99.9999', '10'));
    }

    public function testEarningAmountHalfUpBoundary(): void
    {
        $this->assertSame('0.0001', AffiliateService::earningAmount('0.0001', '50'));
    }
}
