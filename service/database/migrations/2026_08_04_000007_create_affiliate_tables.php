<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        // install.sql 已建全部表（现代 schema），迁移驱动安装才执行本文件
        if ($schema->hasTable('affiliate_plans') && $schema->hasTable('affiliate_links')
            && $schema->hasTable('affiliate_earnings') && $schema->hasTable('affiliate_payouts')) {
            return;
        }
        $schema->create('affiliate_plans', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('name', 128);
            $table->decimal('commission_rate', 5, 2)->comment('e.g. 10.00 = 10%');
            $table->integer('tier')->default(1);
            $table->decimal('min_payout', 12, 4)->default(50.0000);
            $table->boolean('lifetime_commissions')->default(false);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate();
        });

        $schema->create('affiliate_links', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('user_id');
            $table->string('code', 32)->unique();
            $table->string('source', 64)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unique('user_id');
        });

        $schema->create('affiliate_earnings', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('affiliate_id');
            $table->bigInteger('order_id');
            $table->bigInteger('user_id')->comment('referred user');
            $table->decimal('rate', 5, 2);
            $table->decimal('amount', 12, 4);
            $table->string('currency', 3)->default('USD');
            $table->string('status', 16)->default('pending')->comment('pending|approved|paid');
            $table->dateTime('created_at')->useCurrent();
            $table->index('affiliate_id');
            $table->index('status');
        });

        $schema->create('affiliate_payouts', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('affiliate_id');
            $table->decimal('amount', 12, 4);
            $table->string('status', 16)->default('pending')->comment('pending|approved|paid');
            $table->text('admin_notes')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate();
            $table->index('affiliate_id');
        });

        $schema->table('users', function (Blueprint $table) {
            if (!$this->hasColumn('users', 'affiliate_code')) {
                $table->string('affiliate_code', 32)->nullable()->after('role')->index();
            }
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        $schema->dropIfExists('affiliate_payouts');
        $schema->dropIfExists('affiliate_earnings');
        $schema->dropIfExists('affiliate_links');
        $schema->dropIfExists('affiliate_plans');
        $schema->table('users', function (Blueprint $table) {
            if ($this->hasColumn('users', 'affiliate_code')) {
                $table->dropColumn('affiliate_code');
            }
        });
    }

    private function hasColumn(string $table, string $column): bool
    {
        $cols = Capsule::select("SHOW COLUMNS FROM `{$table}` LIKE '" . addslashes($column) . "'");
        return !empty($cols);
    }
};
