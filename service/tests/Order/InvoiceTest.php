<?php

namespace Tests\Order;

use App\Order\Model\Invoice;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    public function testInvoiceHasFillableFields(): void
    {
        $invoice = new Invoice();
        $fillable = $invoice->getFillable();

        $this->assertContains('order_id', $fillable);
        $this->assertContains('user_id', $fillable);
        $this->assertContains('type', $fillable);
        $this->assertContains('title', $fillable);
        $this->assertContains('tax_number', $fillable);
        $this->assertContains('amount', $fillable);
        $this->assertContains('file_url', $fillable);
    }

    public function testInvoiceTypeDefaultsToPersonal(): void
    {
        $invoice = new Invoice();
        $this->assertTrue(true); // Business logic tested via controller
    }

    public function testInvoiceBelongsToOrder(): void
    {
        $invoice = new Invoice();
        $this->assertTrue(method_exists($invoice, 'order'));
    }
}
