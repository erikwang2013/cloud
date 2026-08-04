<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        $schema->create('resource_metrics', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('resource_id');
            $table->string('metric', 32);
            $table->decimal('value', 20, 4);
            $table->dateTime('sample_at');
            $table->index(['resource_id', 'metric', 'sample_at']);
        });

        $schema->create('usage_events', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('resource_id');
            $table->bigInteger('order_item_id')->nullable();
            $table->string('meter', 32);
            $table->decimal('quantity', 20, 6);
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->string('status', 16)->default('open');
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['resource_id', 'meter', 'period_start'], 'uk_event');
            $table->index(['status', 'period_end']);
        });

        $schema->create('usage_rates', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('sku_id');
            $table->bigInteger('region_id')->nullable();
            $table->string('meter', 32);
            $table->decimal('unit_price', 16, 8);
            $table->string('currency', 3)->default('USD');
            $table->string('unit', 16)->default('GB');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate();
            $table->unique(['sku_id', 'region_id', 'meter'], 'uk_rate');
        });

        $schema->create('usage_invoice_items', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('order_id')->nullable();
            $table->bigInteger('resource_id');
            $table->string('meter', 32);
            $table->decimal('quantity', 20, 6);
            $table->decimal('amount', 16, 4);
            $table->string('currency', 3)->default('USD');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->dateTime('created_at')->useCurrent();
            $table->index('resource_id');
        });

        $schema->table('product_skus', function (Blueprint $table) {
            if (!$this->hasColumn('product_skus', 'billing_model')) {
                $table->string('billing_model', 20)->default('fixed')->after('status');
            }
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        $schema->dropIfExists('usage_invoice_items');
        $schema->dropIfExists('usage_rates');
        $schema->dropIfExists('usage_events');
        $schema->dropIfExists('resource_metrics');
        $schema->table('product_skus', function (Blueprint $table) {
            if ($this->hasColumn('product_skus', 'billing_model')) {
                $table->dropColumn('billing_model');
            }
        });
    }

    private function hasColumn(string $table, string $column): bool
    {
        $columns = Capsule::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]);
        return !empty($columns);
    }
};
