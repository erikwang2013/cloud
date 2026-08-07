<?php
namespace App\User\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use Erikwang2013\Encryptable\Encryptable;

class UserKyc extends Model
{
    use HasSnowflakeId;
    protected $table = 'user_kyc';
    protected $fillable = [
        'user_id', 'id_type', 'id_number_encrypted',
        'real_name', 'front_image', 'back_image',
        'status', 'reject_reason', 'verified_at', 'verified_by',
    ];

    protected $casts = [
        'id_number_encrypted' => Encryptable::class,
        'real_name'           => Encryptable::class,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
