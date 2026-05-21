<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        if (Capsule::schema()->hasTable('supplier_api_keys')) {
            return;
        }

        Capsule::schema()->create('supplier_api_keys', function (Blueprint $table) {
            $table->bigInteger('id')->unsigned()->primary();
            $table->bigInteger('supplier_id')->unsigned();
            $table->string('name', 64)->nullable();
            $table->string('key_hash', 64)->unique();
            $table->string('key_prefix', 10);
            $table->boolean('revoked')->default(false);
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('supplier_api_keys');
    }
};
