<?php
namespace App\supplier\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class SupplierWithdraw extends Model
{
    use HasSnowflakeId;
    protected $table = 'supplier_withdraws';
    protected $fillable = [
        'supplier_id', 'amount', 'method', 'account_info', 'status',
        'handled_by', 'handled_at',
    ];

    protected $casts = ['account_info' => 'array'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
