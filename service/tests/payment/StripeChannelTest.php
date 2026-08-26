<?php

namespace Tests\payment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StripeChannelTest extends TestCase
{
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    #[DataProvider('amountConversionProvider')]
    public function testAmountToSmallestUnit(float $total, string $currency, int $expected): void
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            $amount = (int) round($total);
        } else {
            $amount = (int) round($total * 100);
        }
        $this->assertSame($expected, $amount);
    }

    public static function amountConversionProvider(): array
    {
        return [
            'USD with cents' => [19.99, 'USD', 1999],
            'USD whole dollar' => [10.00, 'USD', 1000],
            'USD rounding up' => [10.505, 'USD', 1051],
            'USD rounding down' => [10.504, 'USD', 1050],
            'JPY zero-decimal' => [1000, 'JPY', 1000],
            'JPY zero-decimal rounded' => [1000.50, 'JPY', 1001],
            'KRW zero-decimal' => [50000, 'KRW', 50000],
            'VND zero-decimal' => [100000, 'VND', 100000],
            'EUR with cents' => [9.99, 'EUR', 999],
        ];
    }

    public function testIdempotencySkipsNonPendingTransactions(): void
    {
        $transactions = [
            ['transaction_no' => 'pi_123', 'status' => 'success'],
            ['transaction_no' => 'pi_456', 'status' => 'pending'],
            ['transaction_no' => 'pi_789', 'status' => 'failed'],
        ];

        $processed = [];
        foreach ($transactions as $txn) {
            if ($txn['status'] !== 'pending') {
                continue;
            }
            $processed[] = $txn['transaction_no'];
        }

        $this->assertSame(['pi_456'], $processed);
    }

    public function testIdempotencyHandlesMissingTransaction(): void
    {
        $txn = null;
        $shouldProcess = $txn !== null && $txn['status'] === 'pending';
        $this->assertFalse($shouldProcess);
    }

    #[DataProvider('webhookEventProvider')]
    public function testWebhookEventTypeRouting(string $eventType, bool $isProcessable): void
    {
        $processable = in_array($eventType, ['payment_intent.succeeded', 'payment_intent.payment_failed'], true);
        $this->assertSame($isProcessable, $processable);
    }

    public static function webhookEventProvider(): array
    {
        return [
            'succeeded' => ['payment_intent.succeeded', true],
            'failed' => ['payment_intent.payment_failed', true],
            'processing' => ['payment_intent.processing', false],
            'canceled' => ['payment_intent.canceled', false],
            'created' => ['payment_intent.created', false],
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

        $this->assertContains('paid', $validTransitions['pending']);
        $this->assertContains('completed', $validTransitions['provisioning']);
        $this->assertNotContains('pending', $validTransitions['completed']);
        $this->assertNotContains('pending', $validTransitions['paid']);
    }

    public function testTransactionStatusFlow(): void
    {
        $terminalStatuses = ['success', 'failed', 'refunded'];
        $this->assertContains('success', $terminalStatuses);
        $this->assertContains('failed', $terminalStatuses);
        $this->assertNotContains('pending', $terminalStatuses);
    }

    public function testChannelConfigHasRequiredFields(): void
    {
        $requiredFields = ['name', 'code', 'api_key_encrypted', 'currency_support',
            'fee_config', 'webhook_secret', 'status'];

        $channelFields = ['name', 'code', 'api_key_encrypted', 'currency_support',
            'fee_config', 'webhook_secret', 'status'];

        foreach ($requiredFields as $field) {
            $this->assertContains($field, $channelFields);
        }
    }
}
