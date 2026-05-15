<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use app\common\Migration;

return new class extends Migration {
    public function up(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../install.sql');
        if ($sql === false || $sql === '') {
            throw new \RuntimeException('Failed to read install.sql');
        }
        Capsule::unprepared($sql);
    }

    public function down(): void
    {
        preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i',
            file_get_contents(__DIR__ . '/../../install.sql') ?: '', $matches);
        $tables = $matches[1] ?? [];

        Capsule::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse($tables) as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Capsule::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
};
