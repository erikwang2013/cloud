<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// product_categories
if (!Capsule::schema()->hasTable('product_categories')) {
    Capsule::schema()->create('product_categories', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->json('name');
        $table->string('icon', 255)->nullable();
        $table->integer('sort')->default(0);
        $table->foreign('parent_id')->references('id')->on('product_categories')->onDelete('set null');
        $table->timestamps();
    });
}


// regions
if (!Capsule::schema()->hasTable('regions')) {
    Capsule::schema()->create('regions', function (Blueprint $table) {
        $table->id();
        $table->string('name', 100);
        $table->string('continent', 50)->nullable();
        $table->string('country', 100)->nullable();
        $table->string('city', 100)->nullable();
        $table->string('data_center', 100)->nullable();
        $table->string('status', 20)->default('active');
        $table->timestamps();
    });
}


// products
if (!Capsule::schema()->hasTable('products')) {
    Capsule::schema()->create('products', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('supplier_id')->nullable();
        $table->unsignedBigInteger('category_id');
        $table->json('name');
        $table->string('slug', 200)->unique();
        $table->json('description')->nullable();
        $table->string('cover', 500)->nullable();
        $table->string('status', 20)->default('draft');
        $table->timestamps();
        $table->index('supplier_id');
        $table->index('category_id');
    });
}


// product_skus
if (!Capsule::schema()->hasTable('product_skus')) {
    Capsule::schema()->create('product_skus', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('product_id');
        $table->json('specs')->nullable();
        $table->string('cycle', 20)->default('monthly');
        $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        $table->timestamps();
    });
}


// product_regions
if (!Capsule::schema()->hasTable('product_regions')) {
    Capsule::schema()->create('product_regions', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('sku_id');
        $table->unsignedBigInteger('region_id');
        $table->decimal('price', 14, 4);
        $table->decimal('original_price', 14, 4)->nullable();
        $table->integer('stock')->default(0);
        $table->string('currency', 5)->default('USD');
        $table->unique(['sku_id', 'region_id', 'currency']);
        $table->foreign('sku_id')->references('id')->on('product_skus')->onDelete('cascade');
        $table->foreign('region_id')->references('id')->on('regions')->onDelete('cascade');
        $table->timestamps();
    });
}


// product_images
if (!Capsule::schema()->hasTable('product_images')) {
    Capsule::schema()->create('product_images', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('product_id');
        $table->string('url', 500);
        $table->integer('sort')->default(0);
        $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        $table->timestamps();
    });
}


// product_attributes
if (!Capsule::schema()->hasTable('product_attributes')) {
    Capsule::schema()->create('product_attributes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('product_id');
        $table->string('key', 100);
        $table->string('value', 500);
        $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        $table->index(['key']);
        $table->timestamps();
    });
}


// product_reviews
if (!Capsule::schema()->hasTable('product_reviews')) {
    Capsule::schema()->create('product_reviews', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('order_id')->nullable();
        $table->tinyInteger('rating')->unsigned();
        $table->text('content')->nullable();
        $table->string('status', 20)->default('pending');
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        $table->unique(['user_id', 'product_id', 'order_id']);
        $table->timestamps();
    });
}


echo "Product tables created.\n";
