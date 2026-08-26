<?php
namespace App\supplier\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class SupplierSettlement extends Model
{
    use HasSnowflakeId;
    protected $table = 'supplier_settlements';
    protected $fillable = [
        'supplier_id', 'period_start', 'period_end',
        'total_sales', 'commission', 'payable', 'status',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
