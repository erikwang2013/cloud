<?php
namespace App\Provisioning\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class Disk extends Model
{
    use HasSnowflakeId;
    protected $table = 'disks';
    protected $fillable = [
        'resource_id', 'host_machine_id', 'vm_id', 'size_gb',
        'disk_type', 'storage_pool', 'device_path', 'status',
    ];

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }

    public function hostMachine()
    {
        return $this->belongsTo(HostMachine::class);
    }

    public function resizes()
    {
        return $this->hasMany(DiskResize::class);
    }
}
