<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        $schema->create('resource_storage_buckets', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('resource_id');
            $table->string('bucket_name', 255);
            $table->string('endpoint', 512);
            $table->string('region', 64)->nullable();
            $table->text('access_key_encrypted')->nullable();
            $table->text('secret_key_encrypted')->nullable();
            $table->integer('quota_gb')->default(10);
            $table->decimal('used_gb', 12, 4)->default(0);
            $table->string('status', 32)->default('pending');
            $table->json('policy')->nullable();
            $table->string('provider_type', 32)->default('s3')->comment('s3|minio');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrentOnUpdate();
            $table->index('resource_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('resource_storage_buckets');
    }
};
