<?php
namespace App\product\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class ProductSku extends Model
{
    use HasSnowflakeId;
    protected $table = 'product_skus';
    protected $casts = ['specs' => 'array'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function regionPrices()
    {
        return $this->hasMany(ProductRegion::class, 'sku_id');
    }
}
