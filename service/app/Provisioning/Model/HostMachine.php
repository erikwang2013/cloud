<?php
namespace App\Provisioning\Model;

use Illuminate\Database\Eloquent\Model;

class HostMachine extends Model
{
    protected $table = 'host_machines';
    protected $fillable = [
        'region_id', 'name', 'ip_address', 'proxmox_node',
        'storage_pool', 'api_token_encrypted', 'specs', 'status',
    ];

    protected $casts = ['specs' => 'array'];

    public function region()
    {
        return $this->belongsTo(\App\Product\Model\Region::class);
    }

    public function ipPools()
    {
        return $this->hasMany(IpPool::class);
    }
}
