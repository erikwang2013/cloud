<?php
namespace App\Provisioning\Model;

use Illuminate\Database\Eloquent\Model;

class DiskResize extends Model
{
    protected $table = 'disk_resizes';
    protected $fillable = [
        'disk_id', 'old_size_gb', 'new_size_gb', 'status', 'finished_at',
    ];

    public function disk()
    {
        return $this->belongsTo(Disk::class);
    }
}
