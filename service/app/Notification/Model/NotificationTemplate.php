<?php
namespace App\Notification\Model;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $table = 'notification_templates';
    protected $fillable = [
        'code', 'name', 'title', 'body', 'channels',
    ];

    protected $casts = [
        'title' => 'array',
        'body'  => 'array',
    ];

    public function getLocalizedTitle(string $locale): string
    {
        $title = $this->title;
        return $title[$locale] ?? $title['en'] ?? '';
    }

    public function getLocalizedBody(string $locale): string
    {
        $body = $this->body;
        return $body[$locale] ?? $body['en'] ?? '';
    }
}
