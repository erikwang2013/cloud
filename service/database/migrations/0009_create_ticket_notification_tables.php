<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// tickets
Capsule::schema()->create('tickets', function (Blueprint $table) {
    $table->id();
    $table->string('ticket_no', 32)->unique();
    $table->unsignedBigInteger('user_id');
    $table->unsignedBigInteger('resource_id')->nullable();
    $table->string('category', 20)->default('general');
    $table->string('priority', 20)->default('normal');
    $table->string('title', 500);
    $table->string('status', 20)->default('open');
    $table->unsignedBigInteger('assigned_to')->nullable();
    $table->unsignedBigInteger('closed_by')->nullable();
    $table->timestamp('closed_at')->nullable();
    $table->timestamp('sla_deadline')->nullable();
    $table->timestamps();
    $table->index(['user_id', 'status']);
    $table->index(['assigned_to', 'status']);
});

// ticket_messages
Capsule::schema()->create('ticket_messages', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('ticket_id');
    $table->unsignedBigInteger('sender_id');
    $table->string('sender_type', 10);
    $table->text('content');
    $table->json('attachments')->nullable();
    $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('cascade');
    $table->timestamps();
});

// notifications
Capsule::schema()->create('notifications', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->string('channel', 20);
    $table->string('template_code', 100)->nullable();
    $table->json('content')->nullable();
    $table->string('send_status', 20)->default('pending');
    $table->timestamp('read_at')->nullable();
    $table->timestamps();
    $table->index(['user_id', 'created_at']);
});

// notification_templates
Capsule::schema()->create('notification_templates', function (Blueprint $table) {
    $table->id();
    $table->string('code', 100)->unique();
    $table->string('name', 200);
    $table->json('channels')->nullable();
    $table->json('title_template')->nullable();
    $table->json('body_template')->nullable();
    $table->json('variables')->nullable();
    $table->timestamps();
});

echo "Ticket and notification tables created.\n";
