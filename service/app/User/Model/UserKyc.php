<?php
namespace App\User\Model;

use Illuminate\Database\Eloquent\Model;

class UserKyc extends Model
{
    protected $table = 'user_kyc';
    protected $fillable = [
        'user_id', 'id_type', 'id_number_encrypted',
        'real_name', 'front_image', 'back_image',
        'status', 'reject_reason', 'verified_at', 'verified_by',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
