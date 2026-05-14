<?php
namespace App\User\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use Maize\Encryptable\Encryptable;

class RefreshToken extends Model
{
    use HasSnowflakeId;
    protected $table = 'refresh_tokens';
    protected $fillable = [
        'user_id', 'token_hash', 'device_fingerprint', 'expires_at', 'revoked',
    ];

    protected $casts = [
        'token_hash'        => Encryptable::class,
        'device_fingerprint' => Encryptable::class,
    ];
}
