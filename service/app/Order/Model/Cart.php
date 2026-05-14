<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;
use App\Product\Model\ProductSku;

class Cart extends Model
{
    protected $table = 'carts';
    protected $fillable = ['user_id', 'sku_id', 'region_id', 'quantity', 'cycle'];

    public function sku()
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }
}
