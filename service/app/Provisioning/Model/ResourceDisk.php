<?php
namespace App\Provisioning\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class ResourceDisk extends Model
{
    use HasSnowflakeId;
    protected $table = 'resource_disks';
    protected $fillable = ['resource_id', 'disk_size', 'disk_type', 'attach_to_resource_id'];

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }
}
