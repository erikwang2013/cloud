<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        Capsule::connection('audit')->getSchemaBuilder()->table('audit_logs', function (Blueprint $table) {
            if (!Capsule::connection('audit')->getSchemaBuilder()->hasColumn('audit_logs', 'client_platform')) {
                $table->string('client_platform', 20)->nullable()->after('user_agent');
            }
        });
    }

    public function down(): void
    {
        Capsule::connection('audit')->getSchemaBuilder()->table('audit_logs', function (Blueprint $table) {
            if (Capsule::connection('audit')->getSchemaBuilder()->hasColumn('audit_logs', 'client_platform')) {
                $table->dropColumn('client_platform');
            }
        });
    }
};
