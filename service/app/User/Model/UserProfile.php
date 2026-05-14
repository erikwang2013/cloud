<?php
namespace App\User\Model;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $table = 'user_profiles';
    protected $fillable = ['user_id', 'avatar', 'nickname', 'country'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
