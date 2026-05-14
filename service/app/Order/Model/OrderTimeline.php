<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class OrderTimeline extends Model
{
    use HasSnowflakeId;
    protected $table = 'order_timeline';
    protected $fillable = ['order_id', 'status', 'operator', 'remark'];
}
