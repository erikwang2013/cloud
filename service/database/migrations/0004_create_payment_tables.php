<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// payment_channels
Capsule::schema()->create('payment_channels', function (Blueprint $table) {
    $table->id();
    $table->string('name', 100);
    $table->string('code', 50)->unique();
    $table->string('api_key_encrypted', 500)->nullable();
    $table->json('currency_support')->nullable();
    $table->json('fee_config')->nullable();
    $table->boolean('is_visible')->default(true);
    $table->json('visible_regions')->nullable();
    $table->decimal('min_amount', 14, 4)->nullable();
    $table->decimal('max_amount', 14, 4)->nullable();
    $table->string('webhook_secret', 255)->nullable();
    $table->string('status', 20)->default('active');
    $table->timestamps();
});

// payment_transactions
Capsule::schema()->create('payment_transactions', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_id');
    $table->unsignedBigInteger('user_id');
    $table->unsignedBigInteger('channel_id');
    $table->decimal('amount', 14, 4);
    $table->string('currency', 5);
    $table->decimal('exchange_rate', 14, 6)->default(1.0);
    $table->decimal('channel_fee', 14, 4)->default(0);
    $table->string('transaction_no', 100)->nullable();
    $table->string('status', 20)->default('pending');
    $table->timestamp('callback_at')->nullable();
    $table->timestamps();
    $table->index(['order_id', 'status']);
    $table->index(['transaction_no']);
});

// payment_reconcile
Capsule::schema()->create('payment_reconcile', function (Blueprint $table) {
    $table->id();
    $table->date('date');
    $table->unsignedBigInteger('channel_id');
    $table->decimal('channel_total', 14, 4)->default(0);
    $table->decimal('system_total', 14, 4)->default(0);
    $table->decimal('diff', 14, 4)->default(0);
    $table->string('status', 20)->default('pending');
    $table->timestamps();
});

echo "Payment tables created.\n";
