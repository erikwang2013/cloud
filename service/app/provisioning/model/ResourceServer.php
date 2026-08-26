<?php
namespace App\provisioning\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class ResourceServer extends Model
{
    use HasSnowflakeId;
    protected $table = 'resource_servers';
    protected $fillable = ['resource_id', 'hostname', 'ip_address', 'login_user', 'login_password_encrypted', 'os', 'cpu', 'ram', 'disk', 'bandwidth', 'panel_url'];
    protected $hidden = ['login_password_encrypted'];

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }
}
