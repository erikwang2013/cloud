<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        // install.sql 的 users 已含 fcm 列（现代 schema），迁移驱动安装才需要本文件
        if (Capsule::schema()->hasColumn('users', 'fcm_token')) {
            // 旧库可能为 TEXT 且建索引失败：先截断超长值，再收紧为 VARCHAR(255)
            Capsule::statement('UPDATE `users` SET `fcm_token` = LEFT(`fcm_token`, 255) WHERE CHAR_LENGTH(`fcm_token`) > 255');
            Capsule::statement('ALTER TABLE `users` MODIFY `fcm_token` VARCHAR(255) DEFAULT NULL');
            // 与 install.sql 的 idx_fcm_token 对齐；information_schema 判存在保证幂等
            $hasIndex = (bool) Capsule::select(
                "SELECT COUNT(*) AS c FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_fcm_token'"
            )[0]->c;
            if (!$hasIndex) {
                Capsule::statement('ALTER TABLE `users` ADD INDEX `idx_fcm_token` (`fcm_token`)');
            }
            return;
        }
        Capsule::schema()->table('users', function (Blueprint $table) {
            $table->string('fcm_token', 255)->nullable()->after('role');
            $table->string('fcm_platform', 16)->nullable()->after('fcm_token');
            $table->index('fcm_token', 'idx_fcm_token');
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
