<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        $schema->table('product_categories', function (Blueprint $table) {
            if (!$this->hasColumn('product_categories', 'type')) {
                $table->string('type', 30)->nullable()->after('slug')->index();
            }
        });

        $map = [1 => 'server', 2 => 'ip', 3 => 'disk', 4 => 'domain'];
        foreach ($map as $id => $type) {
            Capsule::table('product_categories')->where('id', $id)->update(['type' => $type]);
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        $schema->table('product_categories', function (Blueprint $table) {
            if ($this->hasColumn('product_categories', 'type')) {
                $table->dropColumn('type');
            }
        });
    }

    private function hasColumn(string $table, string $column): bool
    {
        $columns = Capsule::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]);
        return !empty($columns);
    }
};
