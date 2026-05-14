<?php
namespace App\Supplier\Model;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'suppliers';
    protected $fillable = [
        'user_id', 'company_name', 'contact_name', 'contact_phone',
        'contact_email', 'status', 'settlement_method',
        'approved_by', 'approved_at',
    ];

    protected $casts = ['approved_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(\App\User\Model\User::class);
    }

    public function settlements()
    {
        return $this->hasMany(SupplierSettlement::class);
    }
}
