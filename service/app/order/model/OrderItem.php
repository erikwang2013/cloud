<?php
namespace App\order\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use App\product\model\ProductSku;
use App\provisioning\model\ProvisionTask;

class OrderItem extends Model
{
    use HasSnowflakeId;
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
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }

    public function tasks()
    {
        return $this->hasMany(ProvisionTask::class, 'order_item_id');
    }
}
