<?php
namespace App\supplier\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use App\user\model\User;
use Erikwang2013\Encryptable\Encryptable;

class Supplier extends Model
{
    use HasSnowflakeId;
    protected $table = 'suppliers';
    protected $fillable = [
        'user_id', 'company_name', 'contact_name', 'contact_phone',
        'contact_email', 'status', 'settlement_method',
        'approved_by', 'approved_at',
    ];

    protected $casts = [
        'approved_at'    => 'datetime',
        'contact_name'   => Encryptable::class,
        'contact_phone'  => Encryptable::class,
        'contact_email'  => Encryptable::class,
    ];

    // 序列化一律不输出联系人 PII（Encryptable cast 在 toArray/toJson 自动解密）
    protected $hidden = ['contact_name', 'contact_phone', 'contact_email'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function settlements()
    {
        return $this->hasMany(SupplierSettlement::class);
    }
}
