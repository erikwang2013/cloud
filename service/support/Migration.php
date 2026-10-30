<?php

namespace support;

use Illuminate\Database\Capsule\Manager as Capsule;

abstract class Migration
{
    abstract public function up(): void;
    abstract public function down(): void;

    /**
     * Prefix-aware table name for raw SQL（Capsule::statement 不会自动加 DB_PREFIX）。
     */
    protected function table(string $name): string
    {
        return Capsule::connection()->getTablePrefix() . $name;
    }
}
