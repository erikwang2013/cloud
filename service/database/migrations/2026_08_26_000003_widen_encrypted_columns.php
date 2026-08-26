<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

/**
 * 加密列扩宽：Encryptable 密文（aes-128-ecb 后 base64 约 24+ 字符）装不进 VARCHAR(20)，
 * 写入即报 DataTooLong / 被 API 测试发现（地址新增 500）。users.phone / user_addresses.phone /
 * suppliers.contact_phone 均属加密列，统一扩到 255。已存在的库补列，新库由本迁移补齐。
 */
return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'users'          => 'phone',
            'user_addresses' => 'phone',
            'suppliers'      => 'contact_phone',
        ] as $table => $column) {
            if ($this->hasColumn($table, $column)) {
                Capsule::statement("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` VARCHAR(255) NULL");
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'users'          => 'phone',
            'user_addresses' => 'phone',
            'suppliers'      => 'contact_phone',
        ] as $table => $column) {
            if ($this->hasColumn($table, $column)) {
                Capsule::statement("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` VARCHAR(20) NULL");
            }
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $columns = Capsule::select("SHOW COLUMNS FROM `{$table}` LIKE '" . addslashes($column) . "'");
        return !empty($columns);
    }
};
