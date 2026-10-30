<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

/**
 * 2026_05_21_000003 早期版本曾给 product_regions 追加 UNIQUE(sku_id, region_id)，
 * 与建表时的 UNIQUE(sku_id, region_id, currency) 冲突：同一 SKU 同一区域的多币种行无法写入。
 * 该 unique 已从性能索引迁移中移除；本迁移幂等清理已执行过旧版本的库。
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasTable('product_regions') && $schema->hasIndex('product_regions', 'idx_regions_sku_region')) {
            $schema->table('product_regions', function (Blueprint $table) {
                $table->dropIndex('idx_regions_sku_region');
            });
        }
    }

    public function down(): void
    {
        // 不恢复：该唯一索引会阻塞多币种定价。
    }
};
