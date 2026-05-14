<?php
namespace App\Domain\Model;

use Illuminate\Database\Eloquent\Model;

class DnsZone extends Model
{
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
        return $this->belongsTo(\App\User\Model\User::class);
    }
}
