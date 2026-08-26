<?php

namespace Tests\payment;

use App\payment\model\PaymentTransaction;
use PHPUnit\Framework\TestCase;

final class PaymentTransactionTest extends TestCase
{
    public function testTableAndSnowflake(): void
    {
        $txn = new PaymentTransaction();
        $this->assertSame('payment_transactions', $txn->getTable());
        $this->assertFalse($txn->getIncrementing());
        $this->assertSame('int', $txn->getKeyType());
    }

    public function testFillableCoversMoneyFields(): void
    {
        $fillable = (new PaymentTransaction())->getFillable();
        foreach (['order_id', 'user_id', 'channel_id', 'amount', 'currency', 'exchange_rate', 'channel_fee', 'transaction_no', 'status', 'callback_at'] as $field) {
            $this->assertContains($field, $fillable);
        }
    }

    public function testRelations(): void
    {
        $txn = new PaymentTransaction();
        $this->assertTrue(method_exists($txn, 'order'));
        $this->assertTrue(method_exists($txn, 'channel'));
    }
}
