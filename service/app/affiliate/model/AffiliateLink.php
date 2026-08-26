<?php
namespace App\affiliate\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class AffiliateLink extends Model
{
    use HasSnowflakeId;
    protected $table = 'affiliate_links';
    protected $fillable = ['user_id', 'code', 'source'];

    public static function generateCode(): string
    {
        return substr(bin2hex(random_bytes(6)), 0, 10);
    }
}
