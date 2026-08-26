<?php

namespace Tests\user;

use App\user\model\UserBalance;
use App\user\model\UserBalanceLog;
use App\user\model\UserProfile;
use PHPUnit\Framework\TestCase;

final class BalanceModelsTest extends TestCase
{
    public function testUserBalanceDefinition(): void
    {
        $balance = new UserBalance();
        $this->assertSame('user_balance', $balance->getTable());
        foreach (['user_id', 'currency', 'balance', 'frozen_balance'] as $field) {
            $this->assertContains($field, $balance->getFillable());
        }
        $this->assertTrue(method_exists($balance, 'user'));
    }

    public function testUserBalanceLogDefinition(): void
    {
        $log = new UserBalanceLog();
        $this->assertSame('user_balance_log', $log->getTable());
        foreach (['user_id', 'type', 'currency', 'amount', 'balance_before', 'balance_after', 'order_id', 'remark'] as $field) {
            $this->assertContains($field, $log->getFillable());
        }
        $this->assertTrue(method_exists($log, 'user'));
    }

    public function testUserProfileDefinition(): void
    {
        $profile = new UserProfile();
        $this->assertSame('user_profiles', $profile->getTable());
        foreach (['user_id', 'avatar', 'nickname', 'country'] as $field) {
            $this->assertContains($field, $profile->getFillable());
        }
        $this->assertTrue(method_exists($profile, 'user'));
    }
}
