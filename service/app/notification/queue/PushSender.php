<?php

namespace App\notification\queue;

use Webman\RedisQueue\Consumer;
use App\notification\model\Notification;
use App\user\model\User;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

class PushSender implements Consumer
{
    public string $queue = 'notification_push';

    public function consume($data)
    {
        $credentialsPath = getenv('FIREBASE_CREDENTIALS_PATH');

        if (!$credentialsPath) {
            \error_log("[PUSH] (dev) User: {$data['user_id']} | Title: {$data['title']}");
            Notification::create([
                'user_id' => $data['user_id'],
                'channel' => 'push',
                'template_code' => $data['code'],
                'content' => ['title' => $data['title'], 'body' => $data['body']],
                'send_status' => 'dev-stub',
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
                'content' => [
                    'title' => $data['title'],
                    'body' => $data['body'],
                    'message_id' => $result['name'] ?? null,
                ],
                'send_status' => 'sent',
            ]);
        } catch (\Kreait\Firebase\Exception\Messaging\InvalidToken $e) {
            $user->fcm_token = null;
            $user->save();

            Notification::create([
                'user_id' => $data['user_id'],
                'channel' => 'push',
                'template_code' => $data['code'],
                'content' => ['error' => 'invalid_token'],
                'send_status' => 'failed',
            ]);
        } catch (\Exception $e) {
            Notification::create([
                'user_id' => $data['user_id'],
                'channel' => 'push',
                'template_code' => $data['code'],
                'content' => [
                    'title' => $data['title'],
                    'error' => $e->getMessage(),
                ],
                'send_status' => 'failed',
            ]);
        }
    }

    private function createMessaging(string $credentialsPath): \Kreait\Firebase\Messaging
    {
        if (file_exists($credentialsPath)) {
            $factory = (new Factory())->withServiceAccount($credentialsPath);
        } else {
            throw new \RuntimeException("Firebase credentials file not found: $credentialsPath");
        }
        return $factory->createMessaging();
    }
}
