<?php
namespace App\User\Model;

use Illuminate\Database\Eloquent\Model;

class UserBalance extends Model
{
    protected $table = 'user_balance';
    protected $fillable = ['user_id', 'currency', 'balance', 'frozen_balance'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
