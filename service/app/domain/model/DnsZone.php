<?php
namespace App\domain\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use App\user\model\User;

class DnsZone extends Model
{
    use HasSnowflakeId;
    protected $table = 'dns_zones';
    protected $fillable = [
        'user_id', 'domain_name',
    ];

    public function records()
    {
        return $this->hasMany(DnsRecord::class, 'zone_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
