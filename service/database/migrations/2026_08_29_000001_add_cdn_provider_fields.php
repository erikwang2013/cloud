<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        // 新装库的 install.sql 已含这些列，仅对旧库补列；表前缀 cloud_ 由 config/database.php 统一注入
        if (!Capsule::schema()->hasTable('resource_cdn')) {
            return;
        }
        if (!Capsule::schema()->hasColumns('resource_cdn', ['provider_type'])) {
            Capsule::schema()->table('resource_cdn', function (Blueprint $table) {
                $table->string('provider_type', 16)->default('cloudflare');
                $table->string('provider_domain_id', 128)->nullable();
                $table->string('zone_id', 64)->nullable();
                $table->json('cert_config')->nullable();
                $table->json('config')->nullable();
                $table->index(['provider_type', 'status'], 'idx_provider');
            });
        }
        if (!Capsule::schema()->hasColumns('resource_cdn', ['provider_account_id'])) {
            Capsule::schema()->table('resource_cdn', function (Blueprint $table) {
                $table->bigInteger('provider_account_id')->nullable()->comment('refs provider_apis.id');
                $table->index('provider_account_id', 'idx_account');
            });
        }
        if (Capsule::schema()->hasTable('provider_apis') && !Capsule::schema()->hasColumns('provider_apis', ['config'])) {
            Capsule::schema()->table('provider_apis', function (Blueprint $table) {
                $table->json('config')->nullable()->comment('non-sensitive provider metadata (zone_id/region...)');
            });
        }
    }

    public function down(): void
    {
        if (!Capsule::schema()->hasTable('resource_cdn')) {
            return;
        }
        if (Capsule::schema()->hasColumns('resource_cdn', ['provider_account_id'])) {
            Capsule::schema()->table('resource_cdn', function (Blueprint $table) {
                $table->dropIndex('idx_account');
                $table->dropColumn('provider_account_id');
            });
        }
        if (Capsule::schema()->hasColumns('resource_cdn', ['provider_type'])) {
            Capsule::schema()->table('resource_cdn', function (Blueprint $table) {
                $table->dropIndex('idx_provider');
                $table->dropColumn(['provider_type', 'provider_domain_id', 'zone_id', 'cert_config', 'config']);
            });
        }
        if (Capsule::schema()->hasTable('provider_apis') && Capsule::schema()->hasColumns('provider_apis', ['config'])) {
            Capsule::schema()->table('provider_apis', function (Blueprint $table) {
                $table->dropColumn('config');
            });
        }
    }
};
