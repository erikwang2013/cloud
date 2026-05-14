<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// audit_logs — uses the audit database connection
Capsule::connection('audit')->getSchemaBuilder()->create('audit_logs', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id')->nullable();
    $table->string('ip', 45);
    $table->string('method', 10);
    $table->string('path', 500);
    $table->string('action', 100);
    $table->json('input')->nullable();
    $table->string('status', 30);
    $table->string('request_id', 32)->nullable();
    $table->string('user_agent', 500)->nullable();
    $table->timestamp('created_at')->nullable();
    $table->index(['user_id', 'created_at']);
    $table->index(['action']);
    $table->index(['created_at']);
});

echo "Audit tables created.\n";
