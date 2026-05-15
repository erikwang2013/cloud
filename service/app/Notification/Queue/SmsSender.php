<?php

namespace App\Notification\Queue;

use Webman\RedisQueue\Consumer;
use App\Notification\Model\Notification;
use Twilio\Rest\Client;
use Twilio\Exceptions\RestException;

class SmsSender implements Consumer
{
    public string $queue = 'notification_sms';

    public function consume($data)
    {
        $sid = getenv('TWILIO_ACCOUNT_SID');
        $token = getenv('TWILIO_AUTH_TOKEN');
        $from = getenv('TWILIO_PHONE_NUMBER');

        if (!$sid || !$token || !$from) {
            \error_log("[SMS] (dev) To: {$data['to']} | Body: {$data['body']}");
            Notification::create([
                'user_id' => $data['user_id'],
                'channel' => 'sms',
                'template_code' => $data['code'],
                'content' => json_encode(['body' => $data['body']]),
                'send_status' => 'sent',
            ]);
            return;
        }

        try {
            $client = new Client($sid, $token);
            $message = $client->messages->create($data['to'], [
                'from' => $from,
                'body' => $data['body'],
            ]);

            Notification::create([
                'user_id' => $data['user_id'],
                'channel' => 'sms',
                'template_code' => $data['code'],
                'content' => json_encode([
                    'body' => $data['body'],
                    'provider_message_id' => $message->sid,
                ]),
                'send_status' => 'sent',
            ]);
        } catch (RestException $e) {
            Notification::create([
                'user_id' => $data['user_id'],
                'channel' => 'sms',
                'template_code' => $data['code'],
                'content' => json_encode([
                    'to' => $data['to'],
                    'error' => $e->getMessage(),
                ]),
                'send_status' => 'failed',
            ]);
            throw $e;
        }
    }
}
