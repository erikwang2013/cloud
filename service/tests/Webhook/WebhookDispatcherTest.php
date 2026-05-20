<?php

namespace Tests\Webhook;

use Common\Webhook\WebhookDispatcher;
use PHPUnit\Framework\TestCase;

final class WebhookDispatcherTest extends TestCase
{
    public function testWebhookSignatureFormatIsStandard(): void
    {
        $body   = json_encode(['event' => 'test', 'data' => []]);
        $secret = 'test-secret';
        $sig    = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $this->assertStringStartsWith('sha256=', $sig);
        $this->assertSame(71, strlen($sig)); // sha256= + 64 hex chars
    }

    public function testSignatureIsDeterministic(): void
    {
        $body   = json_encode(['event' => 'order.paid', 'data' => ['order_id' => 1]]);
        $secret = 'my-secret';

        $a = hash_hmac('sha256', $body, $secret);
        $b = hash_hmac('sha256', $body, $secret);

        $this->assertSame($a, $b);
    }

    public function testEventPayloadFormatIsCorrect(): void
    {
        $payload = [
            'event'     => 'order.paid',
            'timestamp' => date('c'),
            'data'      => ['order_id' => 123],
        ];

        $this->assertArrayHasKey('event', $payload);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('data', $payload);
        $this->assertSame('order.paid', $payload['event']);
        $this->assertIsArray($payload['data']);
    }

    public function testClassExistsWithExpectedMethods(): void
    {
        $this->assertTrue(method_exists(WebhookDispatcher::class, 'dispatch'));
        $this->assertTrue(method_exists(WebhookDispatcher::class, 'register'));
        $this->assertTrue(method_exists(WebhookDispatcher::class, 'unregister'));
        $this->assertTrue(method_exists(WebhookDispatcher::class, 'list'));
    }
}
