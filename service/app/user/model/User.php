<?php
namespace App\user\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use Illuminate\Database\Eloquent\SoftDeletes;
use Erikwang2013\Encryptable\Encryptable;
use Erikwang2013\WebmanScout\Searchable;

class User extends Model
{
    use HasSnowflakeId;
    use SoftDeletes;
    use Searchable;

    protected $fillable = [
        'email', 'phone', 'password_hash', 'language',
        'currency', 'timezone', 'status', 'role',
        'fcm_token', 'fcm_platform',
        'email_verify_token', 'email_verify_expires_at', 'email_verified_at',
        'totp_secret', 'totp_enabled',
        'last_login_ip', 'last_login_at', 'notification_prefs',
    ];

    protected $hidden = [
        'password_hash', 'deleted_at',
        'totp_secret', 'email_verify_token', 'email_verify_expires_at',
        'last_login_ip', 'email_verified_at',
    ];

    protected $casts = [
        'email'              => Encryptable::class,
        'phone'              => Encryptable::class,
        'password_hash'      => Encryptable::class,
        'totp_secret'        => Encryptable::class,
        'email_verify_token' => Encryptable::class,
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
