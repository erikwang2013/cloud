<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        $schema->create('supplier_ratings', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('supplier_id');
            $table->bigInteger('user_id');
            $table->bigInteger('order_id');
            $table->tinyInteger('rating')->unsigned()->comment('1-5');
            $table->tinyInteger('quality')->unsigned()->default(0);
            $table->tinyInteger('support')->unsigned()->default(0);
            $table->tinyInteger('delivery_speed')->unsigned()->default(0);
            $table->tinyInteger('value')->unsigned()->default(0);
            $table->text('content')->nullable();
            $table->string('status', 16)->default('published')->comment('published|hidden');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate();
            $table->unique(['user_id', 'order_id'], 'uk_user_order');
            $table->index('supplier_id');
            $table->index('status');
        });

        $schema->table('suppliers', function (Blueprint $table) {
            if (!$this->hasColumn('suppliers', 'rating_avg')) {
                $table->decimal('rating_avg', 3, 2)->default(0)->after('status');
            }
            if (!$this->hasColumn('suppliers', 'rating_count')) {
                $table->integer('rating_count')->default(0)->after('rating_avg');
            }
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        $schema->dropIfExists('supplier_ratings');
        $schema->table('suppliers', function (Blueprint $table) {
            if ($this->hasColumn('suppliers', 'rating_avg')) {
                $table->dropColumn('rating_avg');
            }
            if ($this->hasColumn('suppliers', 'rating_count')) {
                $table->dropColumn('rating_count');
            }
        });
    }

    private function hasColumn(string $table, string $column): bool
    {
        $cols = Capsule::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]);
        return !empty($cols);
    }
};
