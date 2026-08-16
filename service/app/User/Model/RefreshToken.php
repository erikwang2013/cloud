<?php
namespace App\User\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use Erikwang2013\Encryptable\Encryptable;

class RefreshToken extends Model
{
    use HasSnowflakeId;
    protected $table = 'refresh_tokens';
    protected $fillable = [
        'user_id', 'token_hash', 'device_fingerprint', 'client_platform', 'expires_at', 'revoked',
    ];

    protected $casts = [
        'token_hash'        => Encryptable::class,
        'device_fingerprint' => Encryptable::class,
    ];

    // 序列化一律不输出 token 哈希与设备指纹
    protected $hidden = ['token_hash', 'device_fingerprint'];
}
