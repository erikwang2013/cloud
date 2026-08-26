<?php
namespace App\monitor\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use App\provisioning\model\Resource;

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
