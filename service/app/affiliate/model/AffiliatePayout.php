<?php
namespace App\affiliate\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class AffiliatePayout extends Model
{
    use HasSnowflakeId;
    protected $table = 'affiliate_payouts';
    protected $fillable = ['affiliate_id', 'amount', 'status', 'admin_notes', 'paid_at'];
    protected $casts = ['paid_at' => 'datetime'];
}
