<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// suppliers
if (!Capsule::schema()->hasTable('suppliers')) {
    Capsule::schema()->create('suppliers', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->unique();
        $table->string('company_name', 200);
        $table->string('contact_name', 100);
        $table->string('contact_phone', 20);
        $table->string('contact_email', 255);
        $table->string('status', 20)->default('pending');
        $table->string('settlement_method', 20)->default('bank');
        $table->unsignedBigInteger('approved_by')->nullable();
        $table->timestamp('approved_at')->nullable();
        $table->timestamps();
    });
}


// supplier_products
if (!Capsule::schema()->hasTable('supplier_products')) {
    Capsule::schema()->create('supplier_products', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('supplier_id');
        $table->unsignedBigInteger('product_id');
        $table->timestamp('approved_at')->nullable();
        $table->decimal('commission_rate', 5, 4)->default(0.1000);
        $table->timestamps();
        $table->unique(['supplier_id', 'product_id']);
    });
}


// supplier_settlements
if (!Capsule::schema()->hasTable('supplier_settlements')) {
    Capsule::schema()->create('supplier_settlements', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('supplier_id');
        $table->date('period_start');
        $table->date('period_end');
        $table->decimal('total_sales', 14, 4)->default(0);
        $table->decimal('commission', 14, 4)->default(0);
        $table->decimal('payable', 14, 4)->default(0);
        $table->string('status', 20)->default('pending');
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });
}


// supplier_withdraws
if (!Capsule::schema()->hasTable('supplier_withdraws')) {
    Capsule::schema()->create('supplier_withdraws', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('supplier_id');
        $table->decimal('amount', 14, 4);
        $table->string('method', 20);
        $table->json('account_info')->nullable();
        $table->string('status', 20)->default('pending');
        $table->unsignedBigInteger('handled_by')->nullable();
        $table->timestamp('handled_at')->nullable();
        $table->timestamps();
    });
}


echo "Supplier tables created.\n";
