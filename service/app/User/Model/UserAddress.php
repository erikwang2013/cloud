<?php
namespace App\User\Model;

use Illuminate\Database\Eloquent\Model;

class UserAddress extends Model
{
    protected $table = 'user_addresses';
    protected $fillable = [
        'user_id', 'type', 'name', 'phone', 'country',
        'state', 'city', 'address', 'postcode', 'is_default',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
