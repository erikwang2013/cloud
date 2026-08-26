<?php

namespace Tests\Cron;

use App\Cron\SupplierSettlement;
use PHPUnit\Framework\TestCase;

final class SupplierSettlementTest extends TestCase
{
    /**
     * D4 修复回归：结算 = total - commission，全程字符串 bcmath，
     * 写 DECIMAL(14,4) 前 bcround 到 4 位。
     * 期望值用独立 bcmath 计算交叉验证后固化。
     */
    public function testSettleUsesBcmath(): void
    {
        $this->assertSame(['1234.5679', '154.9383', '1079.6296'], SupplierSettlement::settle('1234.56789', '12.55'));
    }

    public function testSettleRoundsHalfUpAt4Scale(): void
    {
        $this->assertSame(['100.0000', '10.0000', '90.0000'], SupplierSettlement::settle('99.99995', '10'));
    }

    public function testSettleLargeTotal(): void
    {
        $this->assertSame(['1000000.1235', '35000.0043', '965000.1192'], SupplierSettlement::settle('1000000.12345', '3.5'));
    }

    public function testSettlePayableIsExactDifference(): void
    {
        [$total, $commission, $payable] = SupplierSettlement::settle('88888.88888', '7.25');
        $this->assertSame(0, bccomp(bcsub($total, $commission, 4), $payable, 4));
        $this->assertSame(4, strlen(substr(strrchr($payable, '.'), 1)));
    }

    public function testSettleZeroCommission(): void
    {
        $this->assertSame(['500.1234', '0.0000', '500.1234'], SupplierSettlement::settle('500.1234', '0'));
    }
}
