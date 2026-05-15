<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        Capsule::schema()->table('erik_users', function (Blueprint $table) {
            $table->text('fcm_token')->nullable()->after('role');
            $table->string('fcm_platform', 16)->nullable()->after('fcm_token');
            $table->index('fcm_token');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('erik_users', function (Blueprint $table) {
            $table->dropColumn('fcm_platform');
            $table->dropColumn('fcm_token');
        });
    }
};
