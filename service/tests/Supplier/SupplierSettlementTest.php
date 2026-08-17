<?php

namespace Tests\Supplier;

use App\Cron\SupplierSettlement;
use PHPUnit\Framework\TestCase;

final class SupplierSettlementTest extends TestCase
{
    public function testSettleNonIntegerPercentRate(): void
    {
        // 12.55%：100.0000 × 0.1255 = 12.5500，payable = 87.4500
        $this->assertSame(['100.0000', '12.5500', '87.4500'], SupplierSettlement::settle('100.0000', '12.55'));
    }

    public function testSettleDefaultRate(): void
    {
        $this->assertSame(['100.0000', '10.0000', '90.0000'], SupplierSettlement::settle('100.0000', '10'));
    }

    public function testSettleRoundsInputTo4dpBeforeMultiply(): void
    {
        // 5 位小数总额先进 4 位（HALF_UP），避免浮点乘法
        $this->assertSame(['10.1235', '1.2705', '8.8530'], SupplierSettlement::settle('10.12345', '12.55'));
    }

    public function testSettleHalfUpOnCommissionBoundary(): void
    {
        // 0.0001 × 50% = 0.00005 → HALF_UP 进 0.0001，payable 归零
        $this->assertSame(['0.0001', '0.0001', '0.0000'], SupplierSettlement::settle('0.0001', '50'));
    }
}
