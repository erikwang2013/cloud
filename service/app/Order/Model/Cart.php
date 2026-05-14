<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use App\Product\Model\ProductSku;

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
