<?php
namespace App\Monitor\Model;

use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    protected $table = 'alerts';
    protected $fillable = [
        'rule_code', 'severity', 'resource_id', 'user_id',
        'context', 'status',
    ];

    protected $casts = ['context' => 'array'];

    public function resource()
    {
        return $this->belongsTo(\App\Provisioning\Model\Resource::class);
    }
}
