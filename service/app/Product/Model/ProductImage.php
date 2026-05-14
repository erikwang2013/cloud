<?php
namespace App\Product\Model;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $table = 'product_images';
    protected $fillable = ['product_id', 'url', 'sort'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
