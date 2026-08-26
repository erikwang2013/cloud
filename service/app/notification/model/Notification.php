<?php
namespace App\notification\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use App\user\model\User;

class Notification extends Model
{
    use HasSnowflakeId;
    protected $table = 'notifications';
    protected $fillable = [
        'user_id', 'channel', 'template_code', 'content', 'send_status',
    ];

    protected $casts = ['content' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
