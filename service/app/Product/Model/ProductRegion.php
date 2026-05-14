<?php
namespace App\Product\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class ProductRegion extends Model
{
    use HasSnowflakeId;
    protected $table = 'product_regions';
    protected $fillable = [
        'sku_id', 'region_id', 'price', 'original_price', 'stock', 'currency',
    ];

    public function sku()
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }
}
