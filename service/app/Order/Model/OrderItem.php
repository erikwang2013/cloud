<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $table = 'order_items';
    protected $fillable = [
        'order_id', 'sku_id', 'region_id', 'product_id',
        'quantity', 'cycle', 'unit_price', 'total_price',
        'resource_snapshot', 'status',
    ];

    protected $casts = ['resource_snapshot' => 'array'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function sku()
    {
        return $this->belongsTo(\App\Product\Model\ProductSku::class, 'sku_id');
    }

    public function tasks()
    {
        return $this->hasMany(\App\Provisioning\Model\ProvisionTask::class, 'order_item_id');
    }
}
