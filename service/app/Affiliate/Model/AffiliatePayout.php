<?php
namespace App\Affiliate\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class AffiliatePayout extends Model
{
    use HasSnowflakeId;
    protected $table = 'affiliate_payouts';
    protected $fillable = ['affiliate_id', 'amount', 'status', 'admin_notes', 'paid_at'];
    protected $casts = ['paid_at' => 'datetime'];
}
