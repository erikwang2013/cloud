<?php
namespace App\Provisioning\Model;

use Illuminate\Database\Eloquent\Model;

class IpAllocation extends Model
{
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
