<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        // install.sql 已建全部表（现代 schema），迁移驱动安装才执行本文件
        if ($schema->hasTable('ssl_plans') && $schema->hasTable('resource_ssl_certs')) {
            return;
        }
        $schema->create('ssl_plans', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('name', 128);
            $table->string('cert_type', 10)->default('DV')->comment('DV|OV|EV');
            $table->string('brand', 64)->nullable();
            $table->integer('validity_days')->default(90);
            $table->string('validation_method', 16)->default('dns-01')->comment('http-01|dns-01');
            $table->boolean('wildcard')->default(false);
            $table->string('ca_provider', 64)->default('letsencrypt')->comment('letsencrypt|zerossl|gogetssl');
            $table->decimal('wholesale_price', 10, 4)->default(0);
            $table->decimal('retail_price', 10, 4)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('status', 32)->default('active');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate();
            $table->index('cert_type');
            $table->index('status');
        });

        $schema->create('resource_ssl_certs', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('resource_id');
            $table->string('domain_name', 255);
            $table->string('cert_type', 10)->default('DV');
            $table->boolean('wildcard')->default(false);
            $table->integer('validity_days')->default(90);
            $table->string('status', 32)->default('pending');
            $table->text('csr')->nullable();
            $table->text('cert_pem')->nullable();
            $table->text('private_key_encrypted')->nullable();
            $table->string('issuer', 128)->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->string('validation_method', 16)->default('http-01');
            $table->json('challenge')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate();
            $table->index('resource_id');
            $table->index('status');
            $table->index('expires_at');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        $schema->dropIfExists('resource_ssl_certs');
        $schema->dropIfExists('ssl_plans');
    }
};
