<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class Refund extends Model
{
    use HasSnowflakeId;
    protected $table = 'refunds';
    protected $fillable = [
        'order_id', 'user_id', 'amount', 'reason', 'status',
        'handled_by', 'reject_reason',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
