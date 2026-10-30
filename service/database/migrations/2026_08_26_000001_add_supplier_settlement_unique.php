<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

/**
 * supplier_settlements 周期幂等：同一供应商同一结算周期最多一条结算单，
 * 防止并发重复生成（TOCTOU）。应用层先查后建 + 此唯一索引兜底，冲突时返回既有结算单。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Capsule::schema()->hasIndex('supplier_settlements', 'uniq_supplier_settlement_period')) {
            $table = $this->table('supplier_settlements');
            Capsule::statement("ALTER TABLE `{$table}`
                ADD UNIQUE INDEX uniq_supplier_settlement_period (supplier_id, period_start, period_end)");
        }
    }

    public function down(): void
    {
        if (Capsule::schema()->hasIndex('supplier_settlements', 'uniq_supplier_settlement_period')) {
            $table = $this->table('supplier_settlements');
            Capsule::statement("ALTER TABLE `{$table}` DROP INDEX uniq_supplier_settlement_period");
        }
    }
};
