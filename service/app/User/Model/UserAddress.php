<?php
namespace App\User\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use Maize\Encryptable\Encryptable;

class UserAddress extends Model
{
    use HasSnowflakeId;
    protected $table = 'user_addresses';
    protected $fillable = [
        'user_id', 'type', 'name', 'phone', 'country',
        'state', 'city', 'address', 'postcode', 'is_default',
    ];

    protected $casts = [
        'phone'   => Encryptable::class,
        'address' => Encryptable::class,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
