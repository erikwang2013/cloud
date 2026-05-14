<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $table = 'carts';
    protected $fillable = ['user_id', 'sku_id', 'region_id', 'quantity', 'cycle'];

    public function sku()
    {
        return $this->belongsTo(\App\Product\Model\ProductSku::class, 'sku_id');
    }
}
