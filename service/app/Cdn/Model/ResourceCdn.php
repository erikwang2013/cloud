<?php
namespace App\Cdn\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class ResourceCdn extends Model
{
    use HasSnowflakeId;
    protected $table = 'resource_cdn';
    protected $fillable = [
        'resource_id', 'cdn_domain', 'origin_type', 'origin_value',
        'plan', 'ssl', 'cache_rules', 'status', 'purged_at',
    ];
    protected $casts = [
        'ssl'         => 'boolean',
        'cache_rules' => 'array',
        'purged_at'   => 'datetime',
    ];

    public function resource()
    {
        return $this->belongsTo(\App\Provisioning\Model\Resource::class, 'resource_id');
    }
}
