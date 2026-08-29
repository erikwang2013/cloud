<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        // install.sql 已建该表（现代 schema），迁移驱动安装才执行本文件；表前缀 cloud_ 由 config/database.php 统一注入
        if (Capsule::schema()->hasTable('resource_cdn')) {
            return;
        }
        Capsule::schema()->create('resource_cdn', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('resource_id');
            $table->string('cdn_domain', 255);
            $table->string('origin_type', 16)->default('server')->comment('server|storage');
            $table->string('origin_value', 512);
            $table->string('plan', 32)->default('standard')->comment('standard|pro|enterprise');
            $table->boolean('ssl')->default(true);
            $table->json('cache_rules')->nullable();
            $table->string('provider_type', 16)->default('cloudflare')->comment('cloudflare|cloudfront|aliyun|tencent');
            $table->bigInteger('provider_account_id')->nullable()->comment('refs provider_apis.id');
            $table->string('provider_domain_id', 128)->nullable();
            $table->string('zone_id', 64)->nullable();
            $table->json('cert_config')->nullable();
            $table->json('config')->nullable();
            $table->string('status', 32)->default('pending')->comment('pending|active|suspended|deleted|failed');
            $table->dateTime('purged_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate();
            $table->index('resource_id');
            $table->index('status');
            $table->index(['provider_type', 'status'], 'idx_provider');
            $table->index('provider_account_id', 'idx_account');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('resource_cdn');
    }
};
