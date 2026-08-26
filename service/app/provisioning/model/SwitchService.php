<?php
namespace App\provisioning\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class SwitchService extends Model
{
    use HasSnowflakeId;
    protected $table = 'switch_services';
    protected $fillable = [
        'host_machine_id', 'resource_id', 'vm_id', 'network_service_id',
        'veth_host', 'veth_guest', 'mac_address', 'status',
    ];

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }

    public function networkService()
    {
        return $this->belongsTo(NetworkService::class, 'network_service_id');
    }
}
