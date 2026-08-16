<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// coupons
Capsule::schema()->create('coupons', function (Blueprint $table) {
    $table->id();
    $table->string('code', 50)->unique();
    $table->string('type', 20)->default('percentage'); // percentage, fixed
    $table->decimal('value', 10, 2); // percentage (e.g. 10.00 = 10%) or fixed amount
    $table->decimal('min_amount', 16, 4)->default(0); // minimum order amount
    $table->decimal('max_discount', 16, 4)->nullable(); // cap for percentage coupons
    $table->integer('max_uses')->default(0); // 0 = unlimited
    $table->integer('used_count')->default(0);
    $table->timestamp('starts_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->string('status', 20)->default('active'); // active, disabled
    $table->timestamps();
});

// user_coupons — tracks which users redeemed which coupons
// 有意不加 (user_id, coupon_id) 唯一约束：同一用户可跨订单多次核销同一优惠券（max_uses 为总量上限）；
// 每行对应一次订单核销，并发由 OrderService 内 coupon 行锁 + 事务保证。
Capsule::schema()->create('user_coupons', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->unsignedBigInteger('coupon_id');
    $table->unsignedBigInteger('order_id')->nullable();
    $table->timestamp('used_at')->nullable();
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    $table->foreign('coupon_id')->references('id')->on('coupons')->onDelete('cascade');
    $table->timestamps();
});

echo "Created coupons tables.\n";
