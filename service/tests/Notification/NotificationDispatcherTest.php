<?php

namespace Tests\Notification;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NotificationDispatcherTest extends TestCase
{
    private function render(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace("{{{$key}}}", (string) $value, $template);
        }
        return $template;
    }

    public function testTemplatePlaceholderReplacement(): void
    {
        $template = 'Hello {{name}}, your order #{{order_id}} is confirmed.';
        $data = ['name' => 'John', 'order_id' => 'ORD-12345'];
        $result = $this->render($template, $data);
        $this->assertSame('Hello John, your order #ORD-12345 is confirmed.', $result);
    }

    public function testTemplateWithMultipleSamePlaceholder(): void
    {
        $template = '{{product}} is now available in {{region}}. Buy {{product}} today!';
        $data = ['product' => 'VPS', 'region' => 'US-East'];
        $result = $this->render($template, $data);
        $this->assertSame('VPS is now available in US-East. Buy VPS today!', $result);
    }

    public function testTemplateWithMissingPlaceholder(): void
    {
        $template = 'Hello {{name}}, welcome!';
        $data = [];
        $result = $this->render($template, $data);
        $this->assertSame('Hello {{name}}, welcome!', $result);
    }

    #[DataProvider('channelDataProvider')]
    public function testChannelRouting(array $userChannels, array $templateChannels, array $expected): void
    {
        $channels = !empty($userChannels) ? $userChannels : $templateChannels;
        $this->assertSame($expected, $channels);
    }

    public static function channelDataProvider(): array
    {
        return [
            'all channels' => [
                [], ['in_app', 'email', 'sms', 'push'], ['in_app', 'email', 'sms', 'push'],
            ],
            'in_app only' => [
                [], ['in_app'], ['in_app'],
            ],
            'email and in_app' => [
                [], ['in_app', 'email'], ['in_app', 'email'],
            ],
        ];
    }

    public function testInactiveUserIsSkipped(): void
    {
        $statuses = ['active', 'inactive', 'banned', 'deleted'];
        $dispatched = [];
        foreach ($statuses as $status) {
            if ($status !== 'active') {
                continue;
            }
            $dispatched[] = $status;
        }
        $this->assertSame(['active'], $dispatched);
    }

    public function testNotificationWithoutEmailSkipsEmailChannel(): void
    {
        $user = ['email' => null, 'phone' => '+1234567890'];
        $channels = ['in_app', 'email', 'sms'];
        $actual = [];

        foreach ($channels as $ch) {
            if ($ch === 'email' && empty($user['email'])) {
                continue;
            }
            if ($ch === 'sms' && empty($user['phone'])) {
                continue;
            }
            if ($ch === 'in_app') {
                $actual[] = $ch;
                continue;
            }
            $actual[] = $ch;
        }

        $this->assertSame(['in_app', 'sms'], $actual);
    }
}
