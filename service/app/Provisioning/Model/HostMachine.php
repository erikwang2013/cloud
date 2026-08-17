<?php
namespace App\Provisioning\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use App\Product\Model\Region;
use Erikwang2013\Encryptable\Encryptable;

class HostMachine extends Model
{
    use HasSnowflakeId;
    protected $table = 'host_machines';
    protected $fillable = [
        'region_id', 'name', 'ip_address', 'proxmox_node',
        'storage_pool', 'api_token_encrypted', 'specs', 'status',
        'hypervisor', 'kvm_connection',
    ];

    protected $casts = [
        'specs'               => 'array',
        'api_token_encrypted' => Encryptable::class,
    ];

    // 序列化一律不输出凭据字段
    protected $hidden = ['api_token_encrypted'];

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function ipPools()
    {
        return $this->hasMany(IpPool::class);
    }
}
