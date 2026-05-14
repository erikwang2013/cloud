<?php
namespace App\User\Model;

use Illuminate\Database\Eloquent\Model;

class RefreshToken extends Model
{
    protected $table = 'refresh_tokens';
    protected $fillable = [
        'user_id', 'token_hash', 'device_fingerprint', 'expires_at', 'revoked',
    ];
}
