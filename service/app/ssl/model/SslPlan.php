<?php
namespace App\ssl\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class SslPlan extends Model
{
    use HasSnowflakeId;
    protected $table = 'ssl_plans';
    protected $fillable = [
        'name', 'cert_type', 'brand', 'validity_days', 'validation_method',
        'wildcard', 'ca_provider', 'wholesale_price', 'retail_price',
        'currency', 'status',
    ];
}
