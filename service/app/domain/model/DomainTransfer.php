<?php
namespace App\domain\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class DomainTransfer extends Model
{
    use HasSnowflakeId;
    protected $table = 'domain_transfers';
    protected $fillable = ['domain_name', 'user_id', 'auth_code_encrypted', 'from_registrar', 'status'];

    // 序列化一律不输出转移授权码
    protected $hidden = ['auth_code_encrypted'];

    public function user()
    {
        return $this->belongsTo(\App\user\model\User::class);
    }
}
