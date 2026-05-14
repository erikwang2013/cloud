<?php
namespace App\Supplier\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use App\User\Model\User;
use Maize\Encryptable\Encryptable;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function settlements()
    {
        return $this->hasMany(SupplierSettlement::class);
    }
}
