<?php

namespace Tests\Billing;

use App\Billing\Cron\SuspendCheck;
use PHPUnit\Framework\TestCase;

final class SuspendCheckTest extends TestCase
{
    private SuspendCheck $check;

    protected function setUp(): void
    {
        parent::setUp();
        $this->check = new SuspendCheck();
    }

    public function testNoOpenDebtAllowsUnsuspend(): void
    {
        $this->assertTrue($this->check->canUnsuspend(['USD' => '0.0000'], []));
    }

    public function testAllCurrencyBucketsCoveredAllowsUnsuspend(): void
    {
        $owed = ['USD' => '3.5000', 'CNY' => '20.0000'];
        $balances = ['USD' => '5.0000', 'CNY' => '25.0000'];
        $this->assertTrue($this->check->canUnsuspend($balances, $owed));
    }

    public function testInsufficientAnyCurrencyBucketBlocksUnsuspend(): void
    {
        $owed = ['USD' => '3.5000', 'CNY' => '20.0000'];
        $balances = ['USD' => '5.0000', 'CNY' => '19.9999'];
        $this->assertFalse($this->check->canUnsuspend($balances, $owed));
    }

    public function testMissingNonUsdAccountFallsBackToUsdBalance(): void
    {
        $owed = ['USD' => '3.5000', 'CNY' => '20.0000'];
        $this->assertTrue($this->check->canUnsuspend(['USD' => '25.0000'], $owed));
        $this->assertFalse($this->check->canUnsuspend(['USD' => '10.0000'], $owed));
    }

    public function testMissingUsdAccountWithUsdDebtBlocksUnsuspend(): void
    {
        $owed = ['USD' => '3.5000'];
        $this->assertFalse($this->check->canUnsuspend(['CNY' => '99.0000'], $owed));
    }
}
