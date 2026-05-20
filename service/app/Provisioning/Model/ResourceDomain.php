<?php
namespace App\Provisioning\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class ResourceDomain extends Model
{
    use HasSnowflakeId;
    protected $table = 'resource_domains';
    protected $fillable = ['resource_id', 'domain_name', 'registrar', 'dns_servers', 'whois_privacy', 'auto_renew'];
    protected $casts = ['dns_servers' => 'array', 'whois_privacy' => 'bool', 'auto_renew' => 'bool'];

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }
}
