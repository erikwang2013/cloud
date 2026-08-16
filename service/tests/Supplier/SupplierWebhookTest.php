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
}
