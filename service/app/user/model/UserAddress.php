<?php
namespace App\user\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use Erikwang2013\Encryptable\Encryptable;

class UserAddress extends Model
{
    use HasSnowflakeId;
    protected $table = 'user_addresses';
    protected $fillable = [
        'user_id', 'type', 'name', 'phone', 'country',
        'state', 'city', 'address', 'postcode', 'is_default',
    ];

    protected $casts = [
        'address' => Encryptable::class,
        'phone'   => Encryptable::class,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
