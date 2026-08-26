<?php
namespace App\provisioning\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class IpAllocation extends Model
{
    use HasSnowflakeId;
    protected $table = 'ip_allocations';
    protected $fillable = [
        'ip_pool_id', 'resource_id', 'ip_address', 'type',
        'allocated_at', 'released_at',
    ];

    public function ipPool()
    {
        return $this->belongsTo(IpPool::class);
    }

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }
}
