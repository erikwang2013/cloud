<?php
namespace App\storage\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

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

    // 序列化一律不输出凭据字段
    protected $hidden = ['access_key_encrypted', 'secret_key_encrypted'];

    public function resource()
    {
        return $this->belongsTo(\App\provisioning\model\Resource::class, 'resource_id');
    }

    public function isQuotaExceeded(): bool
    {
        return $this->used_gb >= $this->quota_gb;
    }
}
