<?php
namespace App\Provisioning\Model;

use Illuminate\Database\Eloquent\Model;

class IpPool extends Model
{
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
