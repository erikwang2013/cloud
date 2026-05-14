<?php
namespace App\Monitor\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use App\Provisioning\Model\Resource;

class Alert extends Model
{
    use HasSnowflakeId;
    protected $table = 'alerts';
    protected $fillable = [
        'rule_code', 'severity', 'resource_id', 'user_id',
        'context', 'status',
    ];

    protected $casts = ['context' => 'array'];

    public function resource()
    {
        return $this->belongsTo(Resource::class);
    }
}
