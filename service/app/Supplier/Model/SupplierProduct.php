<?php
namespace App\Supplier\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class SupplierProduct extends Model
{
    use HasSnowflakeId;
    protected $table = 'supplier_products';
    protected $fillable = ['supplier_id', 'product_id', 'approved_at', 'commission_rate'];

    protected $casts = ['approved_at' => 'datetime', 'commission_rate' => 'decimal:4'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
