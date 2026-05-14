<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;

class OrderTimeline extends Model
{
    protected $table = 'order_timeline';
    protected $fillable = ['order_id', 'status', 'operator', 'remark'];
}
