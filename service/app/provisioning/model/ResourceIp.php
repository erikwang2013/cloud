<?php
namespace App\provisioning\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class ResourceIp extends Model
{
    use HasSnowflakeId;
    protected $table = 'resource_ips';
    protected $fillable = ['resource_id', 'ip_address', 'subnet', 'gateway', 'rdns'];

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }
}
