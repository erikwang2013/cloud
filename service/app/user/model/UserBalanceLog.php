<?php
namespace App\user\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class UserBalanceLog extends Model
{
    use HasSnowflakeId;
    protected $table = 'user_balance_log';
    protected $fillable = ['user_id', 'type', 'currency', 'amount', 'balance_before', 'balance_after', 'order_id', 'remark'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
