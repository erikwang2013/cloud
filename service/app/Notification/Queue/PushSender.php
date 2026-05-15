<?php

namespace App\Notification\Queue;

use Webman\RedisQueue\Consumer;
use App\Notification\Model\Notification;
use App\User\Model\User;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

class PushSender implements Consumer
{
    public string $queue = 'notification_push';

    public function consume($data)
    {
        $credentialsPath = getenv('FIREBASE_CREDENTIALS_PATH');
        $serverKey = getenv('FCM_SERVER_KEY');

        if (!$credentialsPath && !$serverKey) {
            \error_log("[PUSH] (dev) User: {$data['user_id']} | Title: {$data['title']}");
            Notification::create([
                'user_id' => $data['user_id'],
                'channel' => 'push',
                'template_code' => $data['code'],
                'content' => json_encode(['title' => $data['title'], 'body' => $data['body']]),
                'send_status' => 'sent',
            ]);
            return;
        }

        $user = User::find($data['user_id']);
        if (!$user || !$user->fcm_token) {
            return;
        }

        try {
            $messaging = $this->createMessaging($credentialsPath);
            $message = CloudMessage::withTarget('token', $user->fcm_token)
                ->withNotification(FcmNotification::create($data['title'], $data['body']));

            $result = $messaging->send($message);

            Notification::create([
                'user_id' => $data['user_id'],
                'channel' => 'push',
                'template_code' => $data['code'],
                'content' => json_encode([
                    'title' => $data['title'],
                    'body' => $data['body'],
                    'message_id' => $result['name'] ?? null,
                ]),
                'send_status' => 'sent',
            ]);
        } catch (\Kreait\Firebase\Exception\Messaging\InvalidToken $e) {
            // Clean up invalid token
            $user->fcm_token = null;
            $user->save();

            Notification::create([
                'user_id' => $data['user_id'],
                'channel' => 'push',
                'template_code' => $data['code'],
                'content' => json_encode(['error' => 'invalid_token']),
                'send_status' => 'failed',
            ]);
        } catch (\Exception $e) {
            Notification::create([
                'user_id' => $data['user_id'],
                'channel' => 'push',
                'template_code' => $data['code'],
                'content' => json_encode([
                    'title' => $data['title'],
                    'error' => $e->getMessage(),
                ]),
                'send_status' => 'failed',
            ]);
            throw $e;
        }
    }

    private function createMessaging(?string $credentialsPath): \Kreait\Firebase\Messaging
    {
        if ($credentialsPath && file_exists($credentialsPath)) {
            $factory = (new Factory())->withServiceAccount($credentialsPath);
        } else {
            $factory = new Factory();
        }
        return $factory->createMessaging();
    }
}
