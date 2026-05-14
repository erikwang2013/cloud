<?php
namespace App\Product\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class Region extends Model
{
    use HasSnowflakeId;
    protected $fillable = ['name', 'continent', 'country', 'city', 'data_center', 'status'];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
