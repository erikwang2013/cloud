<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        Capsule::schema()->create('resource_cdn', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('resource_id');
            $table->string('cdn_domain', 255);
            $table->string('origin_type', 16)->default('server')->comment('server|storage');
            $table->string('origin_value', 512);
            $table->string('plan', 32)->default('standard')->comment('standard|pro|enterprise');
            $table->boolean('ssl')->default(true);
            $table->json('cache_rules')->nullable();
            $table->string('status', 32)->default('pending')->comment('pending|active|suspended|deleted');
            $table->dateTime('purged_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate();
            $table->index('resource_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('resource_cdn');
    }
};
