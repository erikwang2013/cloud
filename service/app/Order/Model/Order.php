<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use App\User\Model\User;
use App\Payment\Model\PaymentTransaction;
use App\Provisioning\Model\Resource;
use Erikwang2013\WebmanScout\Searchable;

class Order extends Model
{
    use HasSnowflakeId;
    use Searchable;
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
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function resources()
    {
        return $this->hasMany(Resource::class);
    }

    public function refund()
    {
        return $this->hasOne(Refund::class);
    }
}
