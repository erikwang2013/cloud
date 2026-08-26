<?php
namespace App\product\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class Region extends Model
{
    use HasSnowflakeId;
    protected $fillable = ['name', 'continent', 'country', 'city', 'data_center', 'status'];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
