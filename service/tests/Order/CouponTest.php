<?php

// The test env's global now() (tests/bootstrap.php) returns a plain string,
// which breaks Coupon::isValid()'s Carbon comparison. Define a Carbon
// now() scoped to the model's namespace so the real code path runs.
namespace App\Order\Model {

    use Illuminate\Support\Carbon;

    function now(): Carbon
    {
        return Carbon::now();
    }
}

namespace Tests\Order {

use App\Order\Model\Coupon;
use PHPUnit\Framework\TestCase;

final class CouponTest extends TestCase
{
    // Tests the real Coupon model: isValid() / calculateDiscount() read
    // attributes only (no DB), so a bare Eloquent instance is enough.
    // Casts are stripped in a subclass: datetime casts would need a DB
    // connection, and Carbon parses plain date strings in comparisons.

    private function coupon(array $attrs): Coupon
    {
        $coupon = new class extends Coupon {
            protected $casts = [];
        };
        $coupon->fill($attrs);
        return $coupon;
    }

    // --- isValid ---

    public function testActiveCouponIsValid(): void
    {
        $this->assertTrue($this->coupon(['status' => 'active'])->isValid());
    }

    public function testDisabledCouponIsInvalid(): void
    {
        $this->assertFalse($this->coupon(['status' => 'disabled'])->isValid());
    }

    public function testExpiredCouponIsInvalid(): void
    {
        $coupon = $this->coupon(['status' => 'active', 'expires_at' => '2020-01-01']);
        $this->assertFalse($coupon->isValid());
    }

    public function testNotYetStartedCouponIsInvalid(): void
    {
        $coupon = $this->coupon(['status' => 'active', 'starts_at' => '2099-01-01']);
        $this->assertFalse($coupon->isValid());
    }

    public function testUsedUpCouponIsInvalid(): void
    {
        $coupon = $this->coupon(['status' => 'active', 'max_uses' => 100, 'used_count' => 100]);
        $this->assertFalse($coupon->isValid());
    }

    public function testUnlimitedUsesCouponIsValid(): void
    {
        $coupon = $this->coupon(['status' => 'active', 'max_uses' => 0, 'used_count' => 999]);
        $this->assertTrue($coupon->isValid());
    }

    // --- calculateDiscount: percentage (D4 字符串 bcmath 路径，返回 4 位小数) ---

    public function testPercentageDiscount(): void
    {
        $d = $this->coupon(['status' => 'active', 'type' => 'percentage', 'value' => 20.00])->calculateDiscount('100.00');
        $this->assertSame('20.0000', $d);
    }

    public function testPercentageDiscountWithMaxCap(): void
    {
        $coupon = $this->coupon(['status' => 'active', 'type' => 'percentage', 'value' => 50.00, 'max_discount' => 25.00]);
        $this->assertSame('25.0000', $coupon->calculateDiscount('100.00'));
    }

    public function testPercentageDiscountBelowMinAmount(): void
    {
        $coupon = $this->coupon(['status' => 'active', 'type' => 'percentage', 'value' => 10.00, 'min_amount' => 50.00]);
        $this->assertSame('0.0000', $coupon->calculateDiscount('30.00'));
    }

    // --- calculateDiscount: fixed ---

    public function testFixedDiscount(): void
    {
        $coupon = $this->coupon(['status' => 'active', 'type' => 'fixed', 'value' => 15.00]);
        $this->assertSame('15.0000', $coupon->calculateDiscount('100.00'));
    }

    public function testFixedDiscountCannotExceedOrderTotal(): void
    {
        $coupon = $this->coupon(['status' => 'active', 'type' => 'fixed', 'value' => 50.00]);
        $this->assertSame('30.0000', $coupon->calculateDiscount('30.00'));
    }

    public function testFixedDiscountBelowMinAmount(): void
    {
        $coupon = $this->coupon(['status' => 'active', 'type' => 'fixed', 'value' => 10.00, 'min_amount' => 100.00]);
        $this->assertSame('0.0000', $coupon->calculateDiscount('50.00'));
    }

    // --- Edge cases ---

    public function testDiscountWithZeroOrderTotal(): void
    {
        $coupon = $this->coupon(['status' => 'active', 'type' => 'fixed', 'value' => 10.00]);
        $this->assertSame('0.0000', $coupon->calculateDiscount('0.00'));
    }

    public function testPercentageDiscountRoundsHalfUpExactly(): void
    {
        // 5 位小数小计 × 10%：bcmath 字符串路径精确舍入，不经浮点
        $coupon = $this->coupon(['status' => 'active', 'type' => 'percentage', 'value' => 10.00]);
        $this->assertSame('0.0123', $coupon->calculateDiscount('0.12345'));
    }

    public function testModelMethodsMatchBusinessRules(): void
    {
        // Sanity: the model's own isValid/calculateDiscount agree on one
        // combined scenario (valid coupon, percentage capped, min_amount).
        $coupon = $this->coupon([
            'status' => 'active',
            'type' => 'percentage',
            'value' => 30.00,
            'min_amount' => 50.00,
            'max_discount' => 12.00,
            'max_uses' => 10,
            'used_count' => 3,
            'starts_at' => '2020-01-01',
            'expires_at' => '2099-01-01',
        ]);
        $this->assertTrue($coupon->isValid());
        $this->assertSame('12.0000', $coupon->calculateDiscount('100.00'));
    }
}
}
