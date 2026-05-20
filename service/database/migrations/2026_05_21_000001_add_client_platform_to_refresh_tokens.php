<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        Capsule::schema()->table('refresh_tokens', function (Blueprint $table) {
            if (!Capsule::schema()->hasColumn('refresh_tokens', 'client_platform')) {
                $table->string('client_platform', 20)->nullable()->after('device_fingerprint');
            }
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('refresh_tokens', function (Blueprint $table) {
            if (Capsule::schema()->hasColumn('refresh_tokens', 'client_platform')) {
                $table->dropColumn('client_platform');
            }
        });
    }
};
