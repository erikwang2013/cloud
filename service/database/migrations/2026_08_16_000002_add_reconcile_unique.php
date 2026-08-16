<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

/**
 * payment_reconcile 同日同通道互斥：cron（02:37）与 admin action=run
 * 并发跑同一天对账时 updateOrInsert 可能重复插入，唯一约束兜底。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->hasIndex('payment_reconcile', 'uniq_reconcile_channel_date')) {
            Capsule::statement('ALTER TABLE payment_reconcile
                ADD UNIQUE INDEX uniq_reconcile_channel_date (channel_id, date)');
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('payment_reconcile', 'uniq_reconcile_channel_date')) {
            Capsule::statement('ALTER TABLE payment_reconcile DROP INDEX uniq_reconcile_channel_date');
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = Capsule::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$indexName]);
        return !empty($indexes);
    }
};
