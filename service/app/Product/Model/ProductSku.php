<?php
namespace App\Product\Model;

use Illuminate\Database\Eloquent\Model;

class ProductSku extends Model
{
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
