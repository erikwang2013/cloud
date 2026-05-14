<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $table = 'refunds';
    protected $fillable = [
        'order_id', 'user_id', 'amount', 'reason', 'status',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
