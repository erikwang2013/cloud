<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// domain_tlds
Capsule::schema()->create('domain_tlds', function (Blueprint $table) {
    $table->id();
    $table->string('tld', 20)->unique();
    $table->decimal('wholesale_price', 14, 4);
    $table->decimal('retail_price', 14, 4);
    $table->string('registrar', 50);
    $table->decimal('promo_price', 14, 4)->nullable();
    $table->timestamp('promo_end_at')->nullable();
    $table->timestamps();
});

// domain_transfers
Capsule::schema()->create('domain_transfers', function (Blueprint $table) {
    $table->id();
    $table->string('domain_name', 255);
    $table->unsignedBigInteger('user_id');
    $table->string('auth_code_encrypted', 500);
    $table->string('from_registrar', 50);
    $table->string('status', 20)->default('pending');
    $table->timestamps();
});

// dns_zones
Capsule::schema()->create('dns_zones', function (Blueprint $table) {
    $table->id();
    $table->string('domain_name', 255)->unique();
    $table->unsignedBigInteger('user_id');
    $table->string('zone_id', 100)->nullable();
    $table->timestamps();
});

// dns_records
Capsule::schema()->create('dns_records', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('zone_id');
    $table->string('type', 10);
    $table->string('name', 255);
    $table->string('value', 500);
    $table->integer('ttl')->default(600);
    $table->integer('priority')->nullable();
    $table->timestamps();
    $table->index(['zone_id', 'type']);
});

echo "Domain tables created.\n";
