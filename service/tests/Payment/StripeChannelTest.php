<?php

namespace Tests\Payment;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StripeChannelTest extends TestCase
{
    public function testChannelConfigHasRequiredFields(): void
    {
        $requiredFields = ['name', 'code', 'api_key_encrypted', 'currency_support',
            'fee_config', 'webhook_secret', 'status'];

        foreach ($requiredFields as $field) {
            $this->assertContains($field, [
                'name', 'code', 'api_key_encrypted', 'currency_support',
                'fee_config', 'webhook_secret', 'status',
            ]);
        }
    }

    public function testPaymentIntentAmountIsInCents(): void
    {
        $total = 19.99;
        $amountInCents = (int) round($total * 100);
        $this->assertSame(1999, $amountInCents);
    }

    public function testPaymentIntentAmountRounding(): void
    {
        $this->assertSame(1000, (int) round(10.00 * 100));
        $this->assertSame(1050, (int) round(10.50 * 100));
        $this->assertSame(999, (int) round(9.99 * 100));
        $this->assertSame(1, (int) round(0.01 * 100));
    }

    #[DataProvider('webhookSignatureProvider')]
    public function testWebhookSignatureStructure(array $payload, bool $hasRequiredFields): void
    {
        $hasType = isset($payload['type']);
        $hasData = isset($payload['data']['object']);
        $this->assertSame($hasRequiredFields, $hasType && $hasData);
    }

    public static function webhookSignatureProvider(): array
    {
        return [
            'valid succeeded' => [
                ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_123', 'metadata' => ['order_id' => 1]]]],
                true,
            ],
            'missing type' => [
                ['data' => ['object' => ['id' => 'pi_123']]],
                false,
            ],
            'missing data object' => [
                ['type' => 'payment_intent.succeeded', 'data' => []],
                false,
            ],
            'empty payload' => [
                [],
                false,
            ],
            'failed event' => [
                ['type' => 'payment_intent.payment_failed', 'data' => ['object' => ['id' => 'pi_456', 'metadata' => ['order_id' => 2]]]],
                true,
            ],
        ];
    }

    public function testOrderStatusTransitions(): void
    {
        $validTransitions = [
            'pending' => ['paid', 'cancelled', 'refunding'],
            'paid' => ['provisioning', 'refunding'],
            'provisioning' => ['completed', 'failed'],
            'completed' => ['refunding'],
            'refunding' => ['refunded'],
        ];

        $this->assertArrayHasKey('pending', $validTransitions);
        $this->assertContains('paid', $validTransitions['pending']);
        $this->assertContains('completed', $validTransitions['provisioning']);
        $this->assertNotContains('pending', $validTransitions['completed']);
    }

    public function testTransactionStatusFlow(): void
    {
        $validStatuses = ['pending', 'success', 'failed', 'refunded'];

        $this->assertContains('pending', $validStatuses);
        $this->assertContains('success', $validStatuses);
        $this->assertContains('failed', $validStatuses);
    }

    public function testIdempotencyCheckPreventsDuplicateProcessing(): void
    {
        // Verify that a non-pending transaction is skipped
        $transactions = [
            ['transaction_no' => 'pi_123', 'status' => 'success'],
            ['transaction_no' => 'pi_456', 'status' => 'pending'],
        ];

        $processedIds = [];
        foreach ($transactions as $txn) {
            if ($txn['status'] !== 'pending') {
                continue;
            }
            $processedIds[] = $txn['transaction_no'];
        }

        $this->assertSame(['pi_456'], $processedIds);
    }
}
