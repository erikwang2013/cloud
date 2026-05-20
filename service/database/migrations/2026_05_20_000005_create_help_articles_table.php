<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

Capsule::schema()->create('help_articles', function (Blueprint $table) {
    $table->id();
    $table->string('category', 50);
    $table->string('title', 200);
    $table->string('slug', 200)->unique();
    $table->text('content');
    $table->string('locale', 10)->default('en-US');
    $table->integer('sort')->default(0);
    $table->string('status', 20)->default('published');
    $table->timestamps();
    $table->index(['category', 'status']);
});

Capsule::schema()->create('supplier_api_keys', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('supplier_id');
    $table->string('name', 100);
    $table->string('key_hash', 255)->unique();
    $table->string('key_prefix', 10); // First 8 chars, visible in UI
    $table->timestamp('expires_at')->nullable();
    $table->timestamp('last_used_at')->nullable();
    $table->boolean('revoked')->default(false);
    $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('cascade');
    $table->timestamps();
});

echo "Created help_articles and supplier_api_keys tables.\n";
