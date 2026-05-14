<?php
namespace App\User\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

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
