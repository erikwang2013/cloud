<?php
namespace App\provisioning\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class IpPool extends Model
{
    use HasSnowflakeId;
    protected $table = 'ip_pools';
    protected $fillable = [
        'host_machine_id', 'ip_start', 'ip_end', 'gateway',
        'total_count', 'used_count',
    ];

    public function hostMachine()
    {
        return $this->belongsTo(HostMachine::class);
    }
}
