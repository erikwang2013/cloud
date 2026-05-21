<?php
namespace App\Supplier\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class SupplierApiKey extends Model
{
    use HasSnowflakeId;
    protected $table = 'supplier_api_keys';
    protected $fillable = ['supplier_id', 'name', 'key_hash', 'key_prefix'];

    protected $casts = [
        'revoked'      => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
