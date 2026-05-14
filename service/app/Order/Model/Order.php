<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_no', 'user_id', 'type', 'status', 'currency',
        'subtotal', 'discount', 'tax', 'total', 'exchange_rate',
        'paid_at',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function timeline()
    {
        return $this->hasMany(OrderTimeline::class)->orderBy('created_at');
    }

    public function user()
    {
        return $this->belongsTo(\App\User\Model\User::class);
    }

    public function transactions()
    {
        return $this->hasMany(\App\Payment\Model\PaymentTransaction::class);
    }

    public function resources()
    {
        return $this->hasMany(\App\Provisioning\Model\Resource::class);
    }

    public function refund()
    {
        return $this->hasOne(Refund::class);
    }
}
