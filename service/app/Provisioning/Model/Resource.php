<?php
namespace App\Provisioning\Model;

use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    protected $table = 'resources';
    protected $fillable = [
        'order_item_id', 'user_id', 'product_id', 'type',
        'provider', 'region_id', 'status', 'specs',
        'provisioned_at', 'expired_at',
    ];

    protected $casts = [
        'specs'          => 'array',
        'provisioned_at' => 'datetime',
        'expired_at'     => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(\App\User\Model\User::class);
    }

    public function product()
    {
        return $this->belongsTo(\App\Product\Model\Product::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(\App\Order\Model\OrderItem::class, 'order_item_id');
    }

    public function tasks()
    {
        return $this->hasMany(ProvisionTask::class);
    }

    public function disks()
    {
        return $this->hasMany(Disk::class);
    }
}
