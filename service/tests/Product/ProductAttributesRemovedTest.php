<?php

namespace Tests\Product;

use PHPUnit\Framework\TestCase;

final class ProductAttributesRemovedTest extends TestCase
{
    public function testModelClassNoLongerExists(): void
    {
        $this->assertFalse(class_exists(\App\Product\Model\ProductAttribute::class));
    }

    public function testSchemaRemovedFromInstallSql(): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/../../../install.sql');
        $this->assertStringNotContainsString('product_attributes', $sql);
    }

    public function testMigrationHistoryPreserved(): void
    {
        $migration = (string) file_get_contents(
            __DIR__ . '/../../database/migrations/0002_create_product_tables.php'
        );
        $this->assertStringContainsString('product_attributes', $migration);
    }
}
