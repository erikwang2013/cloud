<?php
namespace App\Supplier\Model;

use Illuminate\Database\Eloquent\Model;

class SupplierWithdraw extends Model
{
    protected $table = 'supplier_withdraws';
    protected $fillable = [
        'supplier_id', 'amount', 'method', 'account_info', 'status',
    ];

    protected $casts = ['account_info' => 'array'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
