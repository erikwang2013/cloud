<?php
namespace App\Supplier\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class SupplierRating extends Model
{
    use HasSnowflakeId;
    protected $table = 'supplier_ratings';
    protected $fillable = [
        'supplier_id', 'user_id', 'order_id',
        'rating', 'quality', 'support', 'delivery_speed', 'value',
        'content', 'status',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\User\Model\User::class, 'user_id');
    }
}
