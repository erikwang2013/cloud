<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        // install.sql 的 users 已含 fcm 列（现代 schema），迁移驱动安装才需要本文件
        if (Capsule::schema()->hasColumn('users', 'fcm_token')) {
            return;
        }
        Capsule::schema()->table('users', function (Blueprint $table) {
            $table->text('fcm_token')->nullable()->after('role');
            $table->string('fcm_platform', 16)->nullable()->after('fcm_token');
            $table->index('fcm_token');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('users', function (Blueprint $table) {
            $table->dropColumn('fcm_platform');
            $table->dropColumn('fcm_token');
        });
    }
};
