<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;
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

    public function calculateDiscount(float $orderTotal): float
    {
        if ($orderTotal < (float) $this->min_amount) return 0;

        if ($this->type === 'percentage') {
            $discount = $orderTotal * ((float) $this->value / 100);
            if ($this->max_discount !== null) {
                $discount = min($discount, (float) $this->max_discount);
            }
            return round($discount, 4);
        }

        // fixed
        return min((float) $this->value, $orderTotal);
    }
}
