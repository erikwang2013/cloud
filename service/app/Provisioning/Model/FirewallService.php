<?php
namespace App\Provisioning\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class FirewallService extends Model
{
    use HasSnowflakeId;
    protected $table = 'firewall_services';
    protected $fillable = [
        'host_machine_id', 'resource_id', 'vm_id',
        'table_name', 'default_policy', 'rules', 'status',
    ];

    protected $casts = [
        'rules' => 'array',
    ];

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }
}
