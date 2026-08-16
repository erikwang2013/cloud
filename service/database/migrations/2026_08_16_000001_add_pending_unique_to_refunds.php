<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

/**
 * refunds 表 pending 态互斥：同一订单同时最多一条 pending 退款，
 * 防止并发退款请求双重扣款（TOCTOU）。pending 转 refunded/failed 后槽位释放。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->hasColumn('refunds', 'pending_order_id')) {
            Capsule::statement('ALTER TABLE refunds
                ADD COLUMN pending_order_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (IF(status = \'pending\', order_id, NULL)) STORED,
                ADD UNIQUE INDEX uniq_refunds_pending_order (pending_order_id)');
        }
    }

    public function down(): void
    {
        if ($this->hasColumn('refunds', 'pending_order_id')) {
            Capsule::statement('ALTER TABLE refunds
                DROP INDEX uniq_refunds_pending_order,
                DROP COLUMN pending_order_id');
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $columns = Capsule::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]);
        return !empty($columns);
    }
};
