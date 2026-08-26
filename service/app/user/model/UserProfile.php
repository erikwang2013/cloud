<?php
namespace App\user\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class UserProfile extends Model
{
    use HasSnowflakeId;
    protected $table = 'user_profiles';
    protected $fillable = ['user_id', 'avatar', 'nickname', 'country'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
