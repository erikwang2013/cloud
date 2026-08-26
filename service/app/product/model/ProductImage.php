<?php
namespace App\product\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

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
