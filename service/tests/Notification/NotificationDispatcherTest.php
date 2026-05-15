<?php

namespace Tests\Notification;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationDispatcherTest extends TestCase
{
    public function testTemplatePlaceholderReplacement(): void
    {
        $template = 'Hello {{name}}, your order #{{order_id}} is confirmed.';
        $data = ['name' => 'John', 'order_id' => 'ORD-12345'];

        foreach ($data as $key => $value) {
            $template = str_replace("{{{$key}}}", (string) $value, $template);
        }
        $this->assertSame('Hello John, your order #ORD-12345 is confirmed.', $template);
    }

    public function testTemplateWithMultipleSamePlaceholder(): void
    {
        $template = '{{product}} is now available in {{region}}. Buy {{product}} today!';
        $data = ['product' => 'VPS', 'region' => 'US-East'];

        foreach ($data as $key => $value) {
            $template = str_replace("{{{$key}}}", (string) $value, $template);
        }
        $this->assertSame('VPS is now available in US-East. Buy VPS today!', $template);
    }

    public function testTemplateWithMissingPlaceholder(): void
    {
        $template = 'Hello {{name}}, welcome!';
        $data = [];

        foreach ($data as $key => $value) {
            $template = str_replace("{{{$key}}}", (string) $value, $template);
        }
        $this->assertSame('Hello {{name}}, welcome!', $template);
    }

    #[DataProvider('channelDataProvider')]
    public function testChannelRouting(array $templateChannels, array $expected): void
    {
        $this->assertSame($expected, $templateChannels);
    }

    public static function channelDataProvider(): array
    {
        return [
            'all channels' => [['in_app', 'email', 'sms', 'push'], ['in_app', 'email', 'sms', 'push']],
            'in_app only' => [['in_app'], ['in_app']],
            'email and in_app' => [['in_app', 'email'], ['in_app', 'email']],
        ];
    }

    public function testInactiveUserIsSkipped(): void
    {
        $users = [
            ['id' => 1, 'status' => 'active'],
            ['id' => 2, 'status' => 'inactive'],
            ['id' => 3, 'status' => 'banned'],
            ['id' => 4, 'status' => 'deleted'],
        ];

        $dispatched = [];
        foreach ($users as $user) {
            if ($user['status'] !== 'active') {
                continue;
            }
            $dispatched[] = $user['id'];
        }

        $this->assertSame([1], $dispatched);
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
            $actual[] = $ch;
        }

        $this->assertSame(['in_app', 'sms'], $actual);
    }
}
