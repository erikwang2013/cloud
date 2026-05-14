<?php
namespace App\Notification\Queue;

use Webman\RedisQueue\Consumer;
use App\Notification\Model\Notification;

class EmailSender implements Consumer
{
    public string $queue = 'notification_email';

    public function consume($data)
    {
        try {
            // In production: use PHPMailer or SendGrid SDK
            // For now, log the email that would be sent
            \error_log("[EMAIL] To: {$data['to']} | Subject: {$data['title']}");

            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'email',
                'template_code' => $data['code'],
                'content'       => json_encode(['title' => $data['title'], 'body' => $data['body']]),
                'send_status'   => 'sent',
            ]);
        } catch (\Exception $e) {
            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'email',
                'template_code' => $data['code'],
                'content'       => json_encode(['to' => $data['to']]),
                'send_status'   => 'failed',
            ]);
            throw $e;
        }
    }
}
