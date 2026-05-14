<?php
namespace App\Product\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class ProductImage extends Model
{
    use HasSnowflakeId;
    protected $table = 'product_images';
    protected $fillable = ['product_id', 'url', 'sort'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
