<?php
namespace App\Product\Model;

use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    protected $fillable = ['name', 'continent', 'country', 'city', 'data_center', 'status'];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
