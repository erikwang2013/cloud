<?php
namespace App\Payment\Model;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $table = 'payment_transactions';
    protected $fillable = [
        'order_id', 'user_id', 'channel_id', 'amount', 'currency',
        'exchange_rate', 'channel_fee', 'transaction_no', 'status', 'callback_at',
    ];

    public function order()
    {
        return $this->belongsTo(\App\Order\Model\Order::class);
    }

    public function channel()
    {
        return $this->belongsTo(PaymentChannel::class, 'channel_id');
    }
}
