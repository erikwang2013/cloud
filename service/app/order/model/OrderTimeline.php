<?php
namespace App\order\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class OrderTimeline extends Model
{
    use HasSnowflakeId;
    protected $table = 'order_timeline';
    protected $fillable = ['order_id', 'status', 'operator', 'remark'];
}
