<?php
namespace App\user\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class UserBalance extends Model
{
    use HasSnowflakeId;
    protected $table = 'user_balance';
    protected $fillable = ['user_id', 'currency', 'balance', 'frozen_balance'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
