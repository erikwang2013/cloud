<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// carts
Capsule::schema()->create('carts', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->unsignedBigInteger('sku_id');
    $table->unsignedBigInteger('region_id');
    $table->integer('quantity')->default(1);
    $table->string('cycle', 20)->default('monthly');
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->unique(['user_id', 'sku_id', 'region_id']);
    $table->timestamps();
});

// orders
Capsule::schema()->create('orders', function (Blueprint $table) {
    $table->id();
    $table->string('order_no', 32)->unique();
    $table->unsignedBigInteger('user_id');
    $table->string('type', 20)->default('new');
    $table->string('status', 20)->default('pending');
    $table->string('currency', 5)->default('USD');
    $table->decimal('subtotal', 14, 4);
    $table->decimal('discount', 14, 4)->default(0);
    $table->decimal('tax', 14, 4)->default(0);
    $table->decimal('total', 14, 4);
    $table->decimal('exchange_rate', 14, 6)->default(1.0);
    $table->timestamp('paid_at')->nullable();
    $table->timestamps();
    $table->index(['user_id', 'status']);
    $table->index(['user_id', 'created_at']);
});

// order_items
Capsule::schema()->create('order_items', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_id');
    $table->unsignedBigInteger('sku_id');
    $table->unsignedBigInteger('region_id');
    $table->unsignedBigInteger('product_id');
    $table->integer('quantity')->default(1);
    $table->string('cycle', 20);
    $table->decimal('unit_price', 14, 4);
    $table->decimal('total_price', 14, 4);
    $table->json('resource_snapshot')->nullable();
    $table->string('status', 20)->default('pending');
    $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
    $table->foreign('sku_id')->references('id')->on('product_skus');
    $table->timestamps();
});

// order_timeline
Capsule::schema()->create('order_timeline', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_id');
    $table->string('status', 30);
    $table->string('operator', 100)->nullable();
    $table->text('remark')->nullable();
    $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
    $table->timestamps();
});

// order_invoices
Capsule::schema()->create('order_invoices', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_id');
    $table->unsignedBigInteger('user_id');
    $table->string('type', 20)->default('personal');
    $table->string('title', 200);
    $table->string('tax_number', 50)->nullable();
    $table->decimal('amount', 14, 4);
    $table->string('file_url', 500)->nullable();
    $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
    $table->timestamps();
});

// refunds
Capsule::schema()->create('refunds', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_id');
    $table->unsignedBigInteger('user_id');
    $table->decimal('amount', 14, 4);
    $table->text('reason')->nullable();
    $table->string('status', 20)->default('pending');
    $table->unsignedBigInteger('handled_by')->nullable();
    $table->text('reject_reason')->nullable();
    $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
    $table->timestamps();
});

echo "Order tables created.\n";
