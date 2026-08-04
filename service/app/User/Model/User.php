<?php
namespace App\User\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use Illuminate\Database\Eloquent\SoftDeletes;
use Maize\Encryptable\Encryptable;
use Erikwang2013\WebmanScout\Searchable;

class User extends Model
{
    use HasSnowflakeId;
    use SoftDeletes;
    use Searchable;

    protected $fillable = [
        'email', 'phone', 'password_hash', 'language',
        'currency', 'timezone', 'status', 'role',
        'fcm_token', 'fcm_platform', 'affiliate_code',
    ];

    protected $hidden = ['password_hash', 'deleted_at'];

    protected $casts = [
        'email'         => Encryptable::class,
        'phone'         => Encryptable::class,
        'password_hash' => Encryptable::class,
    ];

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function kyc()
    {
        return $this->hasOne(UserKyc::class);
    }

    public function balances()
    {
        return $this->hasMany(UserBalance::class);
    }

    public function addresses()
    {
        return $this->hasMany(UserAddress::class);
    }
}
