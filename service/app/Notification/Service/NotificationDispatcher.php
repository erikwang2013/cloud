<?php
namespace App\Notification\Service;

use App\User\Model\User;
use App\Notification\Model\Notification;
use App\Notification\Model\NotificationTemplate;
use Common\I18n\I18n;
use Webman\RedisQueue\Client;

class NotificationDispatcher
{
    public function dispatch(int $userId, string $code, array $data = [], array $channels = []): void
    {
        $user = User::with('profile')->find($userId);
        if (!$user || $user->status !== 'active') return;

        $locale = $user->language ?? 'en';

        $template = NotificationTemplate::where('code', $code)->first();
        if (!$template) return;

        $title = $this->renderTemplate($template->getLocalizedTitle($locale), $data);
        $body  = $this->renderTemplate($template->getLocalizedBody($locale), $data);

        if (empty($channels)) {
            $userChannels = $template->channels;
            $channels = is_array($userChannels) ? $userChannels : explode(',', $template->channels ?? 'in_app');
        }

        if (in_array('in_app', $channels)) {
            Notification::create([
                'user_id'       => $userId,
                'channel'       => 'in_app',
                'template_code' => $code,
                'content'       => json_encode(['title' => $title, 'body' => $body]),
                'send_status'   => 'sent',
            ]);
        }

        if (in_array('email', $channels) && $user->email) {
            Client::send('notification_email', [
                'to'      => $user->email,
                'title'   => $title,
                'body'    => $body,
                'user_id' => $userId,
                'code'    => $code,
            ]);
        }

        if (in_array('sms', $channels) && $user->phone) {
            Client::send('notification_sms', [
                'to'      => $user->phone,
                'body'    => $body,
                'user_id' => $userId,
                'code'    => $code,
            ]);
        }

        if (in_array('push', $channels)) {
            Client::send('notification_push', [
                'user_id' => $userId,
                'title'   => $title,
                'body'    => $body,
                'code'    => $code,
            ]);
        }
    }

    private function renderTemplate(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace("{{{$key}}}", (string)$value, $template);
        }
        return $template;
    }
}
