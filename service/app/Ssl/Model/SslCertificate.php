<?php
namespace App\Ssl\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class SslCertificate extends Model
{
    use HasSnowflakeId;
    protected $table = 'resource_ssl_certs';
    protected $fillable = [
        'resource_id', 'domain_name', 'cert_type', 'wildcard',
        'validity_days', 'status', 'csr', 'cert_pem',
        'private_key_encrypted', 'issuer', 'issued_at', 'expires_at',
        'auto_renew', 'validation_method', 'challenge', 'last_checked_at',
    ];

    protected $casts = [
        'wildcard'          => 'boolean',
        'auto_renew'        => 'boolean',
        'challenge'         => 'array',
        'issued_at'         => 'datetime',
        'expires_at'        => 'datetime',
        'last_checked_at'   => 'datetime',
    ];

    // 序列化一律不输出私钥
    protected $hidden = ['private_key_encrypted'];

    public function resource()
    {
        return $this->belongsTo(\App\Provisioning\Model\Resource::class, 'resource_id');
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        if (!$this->expires_at) return false;
        return $this->expires_at->diffInDays(now()) <= $days;
    }

    public function isExpired(): bool
    {
        if (!$this->expires_at) return false;
        return $this->expires_at->isPast();
    }
}
