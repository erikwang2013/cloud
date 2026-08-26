<?php
namespace App\order\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use App\user\model\User;
use App\payment\model\PaymentTransaction;
use App\provisioning\model\Resource;
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
