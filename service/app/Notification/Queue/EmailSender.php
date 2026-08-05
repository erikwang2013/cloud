<?php
namespace App\Notification\Queue;

use Webman\RedisQueue\Consumer;
use App\Notification\Model\Notification;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

class EmailSender implements Consumer
{
    public string $queue = 'notification_email';

    public function consume($data)
    {
        $host = getenv('SMTP_HOST');
        if (empty($host)) {
            // SMTP 未配置 —— dev stub（仅记录日志，不伪造 sent）
            \error_log("[EMAIL] (dev) To: {$data['to']} | Subject: {$data['title']}");
            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'email',
                'template_code' => $data['code'],
                'content'       => ['title' => $data['title'], 'body' => $data['body']],
                'send_status'   => 'dev-stub',
            ]);
            return;
        }

        try {
            $transport = new EsmtpTransport(
                $host,
                (int) (getenv('SMTP_PORT') ?: 587),
                $this->tlsMode((string) getenv('SMTP_ENCRYPTION'))
            );
            if (getenv('SMTP_USERNAME')) {
                $transport->setUsername(getenv('SMTP_USERNAME'));
                $transport->setPassword(getenv('SMTP_PASSWORD'));
            }

            $mailer = new Mailer($transport);
            $email = (new Email())
                ->from(getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@localhost')
                ->to($data['to'])
                ->subject($data['title'])
                ->text($data['body']);
            $mailer->send($email);

            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'email',
                'template_code' => $data['code'],
                'content'       => ['title' => $data['title'], 'body' => $data['body']],
                'send_status'   => 'sent',
            ]);
        } catch (\Exception $e) {
            \error_log("[EMAIL] Failed to {$data['to']}: {$e->getMessage()}");
            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'email',
                'template_code' => $data['code'],
                'content'       => [
                    'to'    => $data['to'],
                    'error' => $e->getMessage(),
                ],
                'send_status' => 'failed',
            ]);
        }
    }

    private function tlsMode(string $encryption): ?bool
    {
        return match (strtolower($encryption)) {
            'ssl'  => true,
            'tls'  => null,
            default => false,
        };
    }
}
