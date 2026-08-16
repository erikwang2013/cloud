<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Money\Money;
use Common\Snowflake\HasSnowflakeId;

class Coupon extends Model
{
    use HasSnowflakeId;
    protected $table = 'coupons';
    protected $fillable = ['code', 'type', 'value', 'min_amount', 'max_discount', 'max_uses', 'used_count', 'starts_at', 'expires_at', 'status'];

    protected $casts = [
        'value'       => 'decimal:2',
        'min_amount'  => 'decimal:4',
        'max_discount'=> 'decimal:4',
        'starts_at'   => 'datetime',
        'expires_at'  => 'datetime',
    ];

    public function isValid(): bool
    {
        if ($this->status !== 'active') return false;
        if ($this->max_uses > 0 && $this->used_count >= $this->max_uses) return false;
        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) return false;
        if ($this->expires_at && $now->gt($this->expires_at)) return false;
        return true;
    }

    public function calculateDiscount(string $orderTotal): string
    {
        // D4：字符串 bcmath 路径，禁止 (float)/round() 混入金额计算链
        if (bccomp($orderTotal, (string) $this->min_amount, 4) < 0) {
            return '0.0000';
        }

        if ($this->type === 'percentage') {
            $discount = bcmul($orderTotal, bcdiv((string) $this->value, '100', 8), 8);
            if ($this->max_discount !== null && bccomp((string) $this->max_discount, '0', 4) > 0
                && bccomp($discount, (string) $this->max_discount, 8) > 0) {
                $discount = (string) $this->max_discount;
            }
            return Money::bcround($discount, 4);
        }

        // fixed：折扣不超过订单金额
        if (bccomp((string) $this->value, $orderTotal, 4) > 0) {
            return Money::bcround($orderTotal, 4);
        }
        return Money::bcround((string) $this->value, 4);
    }
}
