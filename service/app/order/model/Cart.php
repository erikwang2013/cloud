<?php
namespace App\order\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use App\product\model\ProductSku;

class Cart extends Model
{
    use HasSnowflakeId;
    protected $table = 'carts';
    protected $fillable = ['user_id', 'sku_id', 'region_id', 'quantity', 'cycle'];

    public function sku()
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }
}
