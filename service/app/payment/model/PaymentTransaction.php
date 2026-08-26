<?php
namespace App\payment\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use App\order\model\Order;

class PaymentTransaction extends Model
{
    use HasSnowflakeId;
    protected $table = 'payment_transactions';
    protected $fillable = [
        'order_id', 'user_id', 'channel_id', 'amount', 'currency',
        'exchange_rate', 'channel_fee', 'transaction_no', 'status', 'callback_at',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function channel()
    {
        return $this->belongsTo(PaymentChannel::class, 'channel_id');
    }
}
