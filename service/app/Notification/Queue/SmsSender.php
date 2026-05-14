<?php
namespace App\Notification\Queue;

use Webman\RedisQueue\Consumer;
use App\Notification\Model\Notification;

class SmsSender implements Consumer
{
    public string $queue = 'notification_sms';

    public function consume($data)
    {
        try {
            // In production: use Twilio SDK or Alibaba Cloud SMS
            \error_log("[SMS] To: {$data['to']} | Body: {$data['body']}");

            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'sms',
                'template_code' => $data['code'],
                'content'       => json_encode(['body' => $data['body']]),
                'send_status'   => 'sent',
            ]);
        } catch (\Exception $e) {
            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'sms',
                'template_code' => $data['code'],
                'send_status'   => 'failed',
            ]);
            throw $e;
        }
    }
}
