<?php
namespace App\order\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

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
