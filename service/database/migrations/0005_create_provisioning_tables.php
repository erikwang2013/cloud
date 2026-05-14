<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// resources
Capsule::schema()->create('resources', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_item_id');
    $table->unsignedBigInteger('user_id');
    $table->unsignedBigInteger('product_id');
    $table->string('type', 30);
    $table->string('provider', 50);
    $table->unsignedBigInteger('region_id');
    $table->string('status', 20)->default('pending');
    $table->timestamp('provisioned_at')->nullable();
    $table->timestamp('expired_at')->nullable();
    $table->timestamps();
    $table->index(['user_id', 'status']);
});

// resource_servers
Capsule::schema()->create('resource_servers', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('resource_id');
    $table->string('hostname', 255)->nullable();
    $table->string('ip_address', 45)->nullable();
    $table->string('login_user', 50)->nullable();
    $table->string('login_password_encrypted', 500)->nullable();
    $table->string('os', 100)->nullable();
    $table->integer('cpu')->default(0);
    $table->integer('ram')->default(0);
    $table->integer('disk')->default(0);
    $table->integer('bandwidth')->default(0);
    $table->string('panel_url', 500)->nullable();
    $table->foreign('resource_id')->references('id')->on('resources')->onDelete('cascade');
    $table->timestamps();
});

// resource_ips
Capsule::schema()->create('resource_ips', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('resource_id');
    $table->string('ip_address', 45);
    $table->string('subnet', 45)->nullable();
    $table->string('gateway', 45)->nullable();
    $table->string('rdns', 255)->nullable();
    $table->foreign('resource_id')->references('id')->on('resources')->onDelete('cascade');
    $table->timestamps();
});

// resource_disks
Capsule::schema()->create('resource_disks', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('resource_id');
    $table->integer('disk_size');
    $table->string('disk_type', 10)->default('ssd');
    $table->unsignedBigInteger('attach_to_resource_id')->nullable();
    $table->foreign('resource_id')->references('id')->on('resources')->onDelete('cascade');
    $table->timestamps();
});

// resource_domains
Capsule::schema()->create('resource_domains', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('resource_id');
    $table->string('domain_name', 255);
    $table->string('registrar', 50)->nullable();
    $table->json('dns_servers')->nullable();
    $table->boolean('whois_privacy')->default(false);
    $table->boolean('auto_renew')->default(true);
    $table->foreign('resource_id')->references('id')->on('resources')->onDelete('cascade');
    $table->timestamps();
});

// provision_tasks
Capsule::schema()->create('provision_tasks', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_id');
    $table->unsignedBigInteger('order_item_id');
    $table->unsignedBigInteger('resource_id')->nullable();
    $table->string('product_type', 30);
    $table->string('provider', 50);
    $table->unsignedBigInteger('region_id');
    $table->string('action', 30);
    $table->string('status', 20)->default('pending');
    $table->json('params')->nullable();
    $table->json('result')->nullable();
    $table->text('last_error')->nullable();
    $table->integer('retry_count')->default(0);
    $table->timestamp('next_retry_at')->nullable();
    $table->timestamps();
    $table->index(['status', 'next_retry_at']);
});

// provider_apis
Capsule::schema()->create('provider_apis', function (Blueprint $table) {
    $table->id();
    $table->string('name', 100);
    $table->string('code', 50)->unique();
    $table->string('api_key_encrypted', 500)->nullable();
    $table->string('api_secret_encrypted', 500)->nullable();
    $table->string('webhook_secret', 255)->nullable();
    $table->string('status', 20)->default('active');
    $table->timestamps();
});

echo "Provisioning tables created.\n";
