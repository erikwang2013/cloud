<?php
namespace App\Notification\Model;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'notifications';
    protected $fillable = [
        'user_id', 'channel', 'template_code', 'content', 'send_status',
    ];

    protected $casts = ['content' => 'array'];

    public function user()
    {
        return $this->belongsTo(\App\User\Model\User::class);
    }
}
