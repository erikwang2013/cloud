<?php
namespace App\Storage\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class StorageBucket extends Model
{
    use HasSnowflakeId;
    protected $table = 'resource_storage_buckets';
    protected $fillable = [
        'resource_id', 'bucket_name', 'endpoint', 'region',
        'access_key_encrypted', 'secret_key_encrypted',
        'quota_gb', 'used_gb', 'status', 'policy', 'provider_type',
    ];
    protected $casts = [
        'quota_gb' => 'integer',
        'used_gb'  => 'decimal:4',
        'policy'   => 'array',
    ];

    public function resource()
    {
        return $this->belongsTo(\App\Provisioning\Model\Resource::class, 'resource_id');
    }

    public function isQuotaExceeded(): bool
    {
        return $this->used_gb >= $this->quota_gb;
    }
}
