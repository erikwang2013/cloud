<?php
namespace App\domain\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class DnsRecord extends Model
{
    use HasSnowflakeId;
    protected $table = 'dns_records';
    protected $fillable = [
        'zone_id', 'type', 'name', 'value', 'ttl', 'priority',
    ];

    public function zone()
    {
        return $this->belongsTo(DnsZone::class, 'zone_id');
    }
}
