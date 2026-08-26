<?php
namespace App\domain\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class DomainTld extends Model
{
    use HasSnowflakeId;
    protected $table = 'domain_tlds';
    protected $fillable = [
        'tld', 'registrar', 'retail_price', 'promo_price', 'promo_end_at',
    ];

    protected $casts = [
        'promo_end_at' => 'datetime',
    ];
}
