<?php

namespace Tests\Supplier;

use Common\Webhook\WebhookDispatcher;
use PHPUnit\Framework\TestCase;

final class SupplierWebhookTest extends TestCase
{
    public function testSevenDocumentedSupplierEventsAreDefined(): void
    {
        $this->assertSame('order.paid', WebhookDispatcher::EVENT_ORDER_PAID);
        $this->assertSame('order.refunded', WebhookDispatcher::EVENT_ORDER_REFUNDED);
        $this->assertSame('resource.provisioned', WebhookDispatcher::EVENT_RESOURCE_PROVISIONED);
        $this->assertSame('resource.expiring', WebhookDispatcher::EVENT_RESOURCE_EXPIRING);
        $this->assertSame('resource.destroyed', WebhookDispatcher::EVENT_RESOURCE_DESTROYED);
        $this->assertSame('settlement.created', WebhookDispatcher::EVENT_SETTLEMENT_CREATED);
        $this->assertSame('withdrawal.approved', WebhookDispatcher::EVENT_WITHDRAWAL_APPROVED);
    }

    public function testVerifySignatureAcceptsValidHmac(): void
    {
        $body   = json_encode(['event' => 'order.paid', 'timestamp' => date('c'), 'data' => ['order_id' => 1]]);
        $secret = 'test-secret';
        $sig    = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $this->assertTrue(WebhookDispatcher::verifySignature($body, $sig, $secret));
    }

    public function testVerifySignatureRejectsTamperedBody(): void
    {
        $secret = 'test-secret';
        $sig    = 'sha256=' . hash_hmac('sha256', 'original-body', $secret);

        $this->assertFalse(WebhookDispatcher::verifySignature('tampered-body', $sig, $secret));
    }

    public function testVerifySignatureRejectsWrongSecret(): void
    {
        $body = json_encode(['event' => 'order.paid']);
        $sig  = 'sha256=' . hash_hmac('sha256', $body, 'other-secret');

        $this->assertFalse(WebhookDispatcher::verifySignature($body, $sig, 'test-secret'));
    }

    public function testVerifySignatureRejectsMalformedSignature(): void
    {
        $this->assertFalse(WebhookDispatcher::verifySignature('{}', 'abc123', 'test-secret'));
    }

    // ── 端到端往返：dispatch() 发出的载荷/签名，供应商侧 verifySignature 必须能验证 ──

    public function testEveryEventRoundTripsThroughVerify(): void
    {
        $events = [
            WebhookDispatcher::EVENT_ORDER_PAID           => ['order_id' => 1001],
            WebhookDispatcher::EVENT_ORDER_REFUNDED       => ['order_id' => 1002, 'refund_id' => 55],
            WebhookDispatcher::EVENT_RESOURCE_PROVISIONED => ['resource_id' => 2001],
            WebhookDispatcher::EVENT_RESOURCE_EXPIRING    => ['resource_id' => 2002, 'days_left' => 7],
            WebhookDispatcher::EVENT_RESOURCE_DESTROYED   => ['resource_id' => 2003],
            WebhookDispatcher::EVENT_SETTLEMENT_CREATED   => ['settlement_id' => 3001, 'amount' => '12.34'],
            WebhookDispatcher::EVENT_WITHDRAWAL_APPROVED  => ['withdrawal_id' => 4001],
        ];

        foreach ($events as $event => $data) {
            $dispatcher = new class extends WebhookDispatcher {
                public static function buildForTest(string $event, array $payload): array
                {
                    $body = self::buildPayload($event, $payload);
                    return [$body, self::signature($body, 'test-secret')];
                }
            };

            [$body, $sig] = $dispatcher::buildForTest($event, $data);
            $this->assertTrue(
                WebhookDispatcher::verifySignature($body, $sig, 'test-secret'),
                "event {$event}: dispatcher 发出的签名应能被供应商侧验证"
            );

            $decoded = json_decode($body, true);
            $this->assertSame($event, $decoded['event']);
            $this->assertArrayHasKey('timestamp', $decoded);
            $this->assertSame($data, $decoded['data']);
        }
    }

    public function testRoundTrippedPayloadRejectsTampering(): void
    {
        $dispatcher = new class extends WebhookDispatcher {
            public static function buildForTest(string $event, array $payload): array
            {
                $body = self::buildPayload($event, $payload);
                return [$body, self::signature($body, 'test-secret')];
            }
        };

        [$body, $sig] = $dispatcher::buildForTest(WebhookDispatcher::EVENT_ORDER_PAID, ['order_id' => 1001]);
        $this->assertTrue(WebhookDispatcher::verifySignature($body, $sig, 'test-secret'));

        $tampered = str_replace('1001', '1002', $body);
        $this->assertFalse(WebhookDispatcher::verifySignature($tampered, $sig, 'test-secret'));
    }
}
