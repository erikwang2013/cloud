<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// users
if (!Capsule::schema()->hasTable('users')) {
    Capsule::schema()->create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email', 255)->unique()->nullable();
        $table->string('phone', 20)->unique()->nullable();
        $table->string('password_hash', 255);
        $table->string('language', 10)->default('en-US');
        $table->string('currency', 5)->default('USD');
        $table->string('timezone', 40)->default('UTC');
        $table->string('status', 20)->default('active');
        $table->string('role', 20)->default('user');
        $table->json('notification_prefs')->nullable();
        $table->softDeletes();
        $table->timestamps();
        $table->index(['email', 'phone']);
    });
}


// user_profiles
if (!Capsule::schema()->hasTable('user_profiles')) {
    Capsule::schema()->create('user_profiles', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('avatar', 500)->nullable();
        $table->string('nickname', 100)->nullable();
        $table->string('country', 10)->nullable();
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->timestamps();
    });
}


// user_kyc
if (!Capsule::schema()->hasTable('user_kyc')) {
    Capsule::schema()->create('user_kyc', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('id_type', 20);
        $table->string('id_number_encrypted', 500);
        $table->string('real_name', 100);
        $table->string('front_image', 500);
        $table->string('back_image', 500)->nullable();
        $table->string('status', 20)->default('pending');
        $table->text('reject_reason')->nullable();
        $table->timestamp('verified_at')->nullable();
        $table->unsignedBigInteger('verified_by')->nullable();
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->timestamps();
    });
}


// user_balance
if (!Capsule::schema()->hasTable('user_balance')) {
    Capsule::schema()->create('user_balance', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('currency', 5)->default('USD');
        $table->decimal('balance', 16, 4)->default(0);
        $table->decimal('frozen_balance', 16, 4)->default(0);
        $table->unique(['user_id', 'currency']);
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->timestamps();
    });
}


// user_balance_log
if (!Capsule::schema()->hasTable('user_balance_log')) {
    Capsule::schema()->create('user_balance_log', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('type', 30);
        $table->string('currency', 5);
        $table->decimal('amount', 16, 4);
        $table->decimal('balance_before', 16, 4);
        $table->decimal('balance_after', 16, 4);
        $table->unsignedBigInteger('order_id')->nullable();
        $table->string('remark', 500)->nullable();
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->timestamps();
        $table->index(['user_id', 'created_at']);
    });
}


// user_addresses
if (!Capsule::schema()->hasTable('user_addresses')) {
    Capsule::schema()->create('user_addresses', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('type', 20)->default('billing');
        $table->string('name', 100);
        $table->string('phone', 20);
        $table->string('country', 10);
        $table->string('state', 100)->nullable();
        $table->string('city', 100);
        $table->string('address', 500);
        $table->string('postcode', 20)->nullable();
        $table->boolean('is_default')->default(false);
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->timestamps();
    });
}


// refresh_tokens
if (!Capsule::schema()->hasTable('refresh_tokens')) {
    Capsule::schema()->create('refresh_tokens', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('token_hash', 255)->unique();
        $table->string('device_fingerprint', 255);
        $table->timestamp('expires_at');
        $table->boolean('revoked')->default(false);
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->timestamps();
    });
}


echo "Users tables created.\n";
