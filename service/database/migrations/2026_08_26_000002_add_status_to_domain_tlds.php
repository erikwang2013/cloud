<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

/**
 * domain_tlds 缺 status 列（0008_create_domain_tables 建表时遗漏，DomainService::getTlds
 * 按 status=active 过滤导致 42S22）。已存在的库补列，新库由本迁移补齐。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!$this->hasColumn('domain_tlds', 'status')) {
            $table = $this->table('domain_tlds');
            Capsule::statement("ALTER TABLE `{$table}` ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER registrar");
        }
    }

    public function down(): void
    {
        if ($this->hasColumn('domain_tlds', 'status')) {
            $table = $this->table('domain_tlds');
            Capsule::statement("ALTER TABLE `{$table}` DROP COLUMN status");
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $table = $this->table($table);
        $columns = Capsule::select("SHOW COLUMNS FROM `{$table}` LIKE '" . addslashes($column) . "'");
        return !empty($columns);
    }
};
