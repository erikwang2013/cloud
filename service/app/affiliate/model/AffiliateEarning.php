<?php
namespace App\affiliate\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class AffiliateEarning extends Model
{
    use HasSnowflakeId;
    protected $table = 'affiliate_earnings';
    protected $fillable = ['affiliate_id', 'order_id', 'user_id', 'rate', 'amount', 'currency', 'status'];
}
