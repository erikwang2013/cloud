<?php
namespace App\Notification\Queue;

use Webman\RedisQueue\Consumer;
use App\Notification\Model\Notification;

class PushSender implements Consumer
{
    public string $queue = 'notification_push';

    public function consume($data)
    {
        try {
            // In production: use Firebase Cloud Messaging (FCM) SDK
            \error_log("[PUSH] User: {$data['user_id']} | Title: {$data['title']}");

            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'push',
                'template_code' => $data['code'],
                'content'       => json_encode(['title' => $data['title'], 'body' => $data['body']]),
                'send_status'   => 'sent',
            ]);
        } catch (\Exception $e) {
            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'push',
                'template_code' => $data['code'],
                'send_status'   => 'failed',
            ]);
            throw $e;
        }
    }
}
