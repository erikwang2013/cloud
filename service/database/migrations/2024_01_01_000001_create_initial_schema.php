<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

/**
 * Import the legacy schema snapshot (docs/database.sql).
 *
 * docs/database.sql is not shipped in the repository: on migration-driven
 * installs the legacy tables are created by 0001..0010 (and install.sql
 * covers the rest with CREATE TABLE IF NOT EXISTS). When the file is
 * absent this migration is a no-op instead of throwing, so it can never
 * halt the whole migration chain for later migrations.
 */
return new class extends Migration {
    public function up(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../../docs/database.sql');
        if ($sql === false || $sql === '') {
            return; // 文件未随仓库分发：legacy 表由 0001..0010 / install.sql 提供
        }
        Capsule::unprepared($sql);
    }

    public function down(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../../docs/database.sql');
        if ($sql === false || $sql === '') {
            return;
        }

        preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $matches);
        $tables = $matches[1] ?? [];

        Capsule::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse($tables) as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Capsule::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
};
