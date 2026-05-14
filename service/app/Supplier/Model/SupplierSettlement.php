<?php
namespace App\Supplier\Model;

use Illuminate\Database\Eloquent\Model;

class SupplierSettlement extends Model
{
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
