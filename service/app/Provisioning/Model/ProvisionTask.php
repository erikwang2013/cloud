<?php
namespace App\Provisioning\Model;

use Illuminate\Database\Eloquent\Model;

class ProvisionTask extends Model
{
    protected $table = 'provision_tasks';
    protected $fillable = [
        'order_id', 'order_item_id', 'resource_id', 'product_type',
        'provider', 'region_id', 'action', 'status', 'params',
        'retry_count', 'last_error', 'next_retry_at',
    ];

    public function orderItem()
    {
        return $this->belongsTo(\App\Order\Model\OrderItem::class, 'order_item_id');
    }

    public function order()
    {
        return $this->belongsTo(\App\Order\Model\Order::class);
    }

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }
}
