<?php
namespace App\provisioning\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

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
