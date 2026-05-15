<?php

use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../../docs/database.sql');
        if ($sql === false) {
            throw new \RuntimeException('Failed to read database.sql');
        }
        \Illuminate\Database\Capsule\Manager::unprepared($sql);
    }

    public function down(): void
    {
        $tables = \Illuminate\Database\Capsule\Manager::select(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );
        \Illuminate\Database\Capsule\Manager::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            \Illuminate\Database\Capsule\Manager::schema()->dropIfExists($table->TABLE_NAME);
        }
        \Illuminate\Database\Capsule\Manager::statement('SET FOREIGN_KEY_CHECKS = 1');
    }
};
