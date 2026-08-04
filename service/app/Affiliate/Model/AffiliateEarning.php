<?php
namespace App\Affiliate\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class AffiliateEarning extends Model
{
    use HasSnowflakeId;
    protected $table = 'affiliate_earnings';
    protected $fillable = ['affiliate_id', 'order_id', 'user_id', 'rate', 'amount', 'currency', 'status'];
}
