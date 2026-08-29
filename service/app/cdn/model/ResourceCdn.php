<?php
namespace App\cdn\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class ResourceCdn extends Model
{
    use HasSnowflakeId;
    protected $table = 'resource_cdn';
    protected $fillable = [
        'resource_id', 'cdn_domain', 'origin_type', 'origin_value',
        'plan', 'ssl', 'cache_rules', 'status', 'purged_at',
        'provider_type', 'provider_account_id', 'provider_domain_id', 'zone_id', 'cert_config', 'config',
    ];
    protected $hidden = ['cert_config'];
    protected $casts = [
        'ssl'         => 'boolean',
        'cache_rules' => 'array',
        'cert_config' => 'array',
        'config'      => 'array',
        'purged_at'   => 'datetime',
    ];

    public function resource()
    {
        return $this->belongsTo(\App\provisioning\model\Resource::class, 'resource_id');
    }
}
