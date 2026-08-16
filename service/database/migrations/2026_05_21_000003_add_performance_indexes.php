<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        // orders — 用户订单列表 + 状态筛选
        $schema->table('orders', function (Blueprint $table) {
            if (!$this->hasIndex('orders', 'idx_orders_user_status_created')) {
                $table->index(['user_id', 'status', 'created_at'], 'idx_orders_user_status_created');
            }
        });

        // products — 前台产品列表 + 分类筛选（install.sql 无 sort 列，跳过该索引）
        $schema->table('products', function (Blueprint $table) {
            if (Capsule::schema()->hasColumn('products', 'sort')
                && !$this->hasIndex('products', 'idx_products_status_category_sort')) {
                $table->index(['status', 'category_id', 'sort'], 'idx_products_status_category_sort');
            }
        });

        // product_skus — SKU 按产品查找（install.sql 无 status 列，跳过该索引）
        $schema->table('product_skus', function (Blueprint $table) {
            if (Capsule::schema()->hasColumn('product_skus', 'status')
                && !$this->hasIndex('product_skus', 'idx_skus_product_status')) {
                $table->index(['product_id', 'status'], 'idx_skus_product_status');
            }
        });

        // product_regions — 区域定价唯一查找
        $schema->table('product_regions', function (Blueprint $table) {
            if (!$this->hasIndex('product_regions', 'idx_regions_sku_region')) {
                $table->unique(['sku_id', 'region_id'], 'idx_regions_sku_region');
            }
        });

        // resources — 用户资源列表
        $schema->table('resources', function (Blueprint $table) {
            if (!$this->hasIndex('resources', 'idx_resources_user_status')) {
                $table->index(['user_id', 'status'], 'idx_resources_user_status');
            }
            if (!$this->hasIndex('resources', 'idx_resources_expired_status')) {
                $table->index(['expired_at', 'status'], 'idx_resources_expired_status');
            }
        });

        // provision_tasks — Worker 轮询待处理任务
        $schema->table('provision_tasks', function (Blueprint $table) {
            if (!$this->hasIndex('provision_tasks', 'idx_tasks_status_retry')) {
                $table->index(['status', 'next_retry_at'], 'idx_tasks_status_retry');
            }
        });

        // refresh_tokens — 会话管理
        $schema->table('refresh_tokens', function (Blueprint $table) {
            if (!$this->hasIndex('refresh_tokens', 'idx_tokens_user_revoked')) {
                $table->index(['user_id', 'revoked'], 'idx_tokens_user_revoked');
            }
        });

        // payment_transactions — 按订单查交易 + webhook 幂等
        $schema->table('payment_transactions', function (Blueprint $table) {
            if (!$this->hasIndex('payment_transactions', 'idx_txns_order')) {
                $table->index(['order_id'], 'idx_txns_order');
            }
            if (!$this->hasIndex('payment_transactions', 'idx_txns_txn_no')) {
                $table->unique(['transaction_no'], 'idx_txns_txn_no');
            }
        });

        // tickets — 用户工单列表
        $schema->table('tickets', function (Blueprint $table) {
            if (!$this->hasIndex('tickets', 'idx_tickets_user_status')) {
                $table->index(['user_id', 'status'], 'idx_tickets_user_status');
            }
        });

        // notifications — 用户通知列表
        $schema->table('notifications', function (Blueprint $table) {
            if (!$this->hasIndex('notifications', 'idx_notifs_user_read_created')) {
                $table->index(['user_id', 'read_at', 'created_at'], 'idx_notifs_user_read_created');
            }
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        $indexes = [
            ['orders', 'idx_orders_user_status_created'],
            ['products', 'idx_products_status_category_sort'],
            ['product_skus', 'idx_skus_product_status'],
            ['product_regions', 'idx_regions_sku_region'],
            ['resources', 'idx_resources_user_status'],
            ['resources', 'idx_resources_expired_status'],
            ['provision_tasks', 'idx_tasks_status_retry'],
            ['refresh_tokens', 'idx_tokens_user_revoked'],
            ['payment_transactions', 'idx_txns_order'],
            ['payment_transactions', 'idx_txns_txn_no'],
            ['tickets', 'idx_tickets_user_status'],
            ['notifications', 'idx_notifs_user_read_created'],
        ];

        foreach ($indexes as [$table, $index]) {
            $schema->table($table, function (Blueprint $table) use ($index) {
                if ($this->hasIndex($table, $index)) {
                    $table->dropIndex($index);
                }
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        $indexes = Capsule::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]);
        return !empty($indexes);
    }
};
