<?php
namespace App\user\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
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

    // 序列化一律不输出证件号（real_name 需供 KYC 审核展示，不隐藏）
    protected $hidden = ['id_number_encrypted'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
