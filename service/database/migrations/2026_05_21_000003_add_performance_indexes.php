<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        // orders — 用户订单列表 + 状态筛选
        $this->addIndex($schema, 'orders', ['user_id', 'status', 'created_at'], 'idx_orders_user_status_created');

        // products — 前台产品列表 + 分类筛选（install.sql 无 sort 列，跳过该索引）
        if ($schema->hasColumn('products', 'sort')) {
            $this->addIndex($schema, 'products', ['status', 'category_id', 'sort'], 'idx_products_status_category_sort');
        }

        // product_skus — SKU 按产品查找（老库可能无 status 列，跳过该索引）
        if ($schema->hasColumn('product_skus', 'status')) {
            $this->addIndex($schema, 'product_skus', ['product_id', 'status'], 'idx_skus_product_status');
        }

        // product_regions — 唯一键已是 UNIQUE(sku_id, region_id, currency)，支撑同 SKU 同区域多币种定价。
        // 不再追加 UNIQUE(sku_id, region_id)：多币种行会让建索引失败并阻塞定价。旧库残留的
        // idx_regions_sku_region 由 2026_10_30_000001 幂等删除。

        // resources — 用户资源列表
        $this->addIndex($schema, 'resources', ['user_id', 'status'], 'idx_resources_user_status');
        $this->addIndex($schema, 'resources', ['expired_at', 'status'], 'idx_resources_expired_status');

        // provision_tasks — Worker 轮询待处理任务（install.sql idx_status_next / 0005 同列索引已存在时跳过）
        $this->addIndex($schema, 'provision_tasks', ['status', 'next_retry_at'], 'idx_tasks_status_retry');

        // refresh_tokens — 会话管理
        $this->addIndex($schema, 'refresh_tokens', ['user_id', 'revoked'], 'idx_tokens_user_revoked');

        // payment_transactions — 按订单查交易 + webhook 幂等
        // install.sql 已有 idx_order(order_id) / uk_transaction_no；0004 建 (order_id,status) / uk_transaction_no。
        // 已存在等列索引时跳过，避免新库重复建索引（旧库已有重名/冗余索引本次不删，避免锁表）。
        if (!$schema->hasIndex('payment_transactions', ['order_id'])
            && !$schema->hasIndex('payment_transactions', ['order_id', 'status'])) {
            $schema->table('payment_transactions', function (Blueprint $table) {
                $table->index(['order_id'], 'idx_txns_order');
            });
        }
        if (!$schema->hasIndex('payment_transactions', ['transaction_no'], 'unique')) {
            $schema->table('payment_transactions', function (Blueprint $table) {
                $table->unique(['transaction_no'], 'idx_txns_txn_no');
            });
        }

        // tickets — 用户工单列表
        $this->addIndex($schema, 'tickets', ['user_id', 'status'], 'idx_tickets_user_status');

        // notifications — 用户通知列表
        $this->addIndex($schema, 'notifications', ['user_id', 'read_at', 'created_at'], 'idx_notifs_user_read_created');
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        $indexes = [
            ['orders', 'idx_orders_user_status_created'],
            ['products', 'idx_products_status_category_sort'],
            ['product_skus', 'idx_skus_product_status'],
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
            if ($schema->hasIndex($table, $index)) {
                $schema->table($table, function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }
    }

    /**
     * 列级幂等建索引：同名或同列（任意名字）索引已存在时跳过。
     */
    private function addIndex(Builder $schema, string $table, array $columns, string $name): void
    {
        if ($schema->hasIndex($table, $columns)) {
            return;
        }
        $schema->table($table, function (Blueprint $table) use ($columns, $name) {
            $table->index($columns, $name);
        });
    }
};
