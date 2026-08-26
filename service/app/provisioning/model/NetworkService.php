<?php
namespace App\provisioning\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class NetworkService extends Model
{
    use HasSnowflakeId;
    protected $table = 'network_services';
    protected $fillable = [
        'host_machine_id', 'resource_id', 'vm_id',
        'bridge_name', 'subnet', 'gateway_ip', 'status',
    ];

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }

    public function switchServices()
    {
        return $this->hasMany(SwitchService::class, 'network_service_id');
    }
}
