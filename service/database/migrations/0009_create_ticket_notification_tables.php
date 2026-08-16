<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// tickets
if (!Capsule::schema()->hasTable('tickets')) {
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
}

// ticket_messages
if (!Capsule::schema()->hasTable('ticket_messages')) {
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
}

// notifications
if (!Capsule::schema()->hasTable('notifications')) {
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
}

// notification_templates
// Canonical shape, aligned with install.sql and App\Notification\Model\NotificationTemplate
// (title/body JSON locale maps + channels VARCHAR). Legacy installs created by the
// previous shape (title_template/body_template JSON) keep working via model fallback.
if (!Capsule::schema()->hasTable('notification_templates')) {
    Capsule::schema()->create('notification_templates', function (Blueprint $table) {
        $table->bigInteger('id')->primary(); // Snowflake id，与 install.sql 一致（无自增）
        $table->string('code', 128)->unique();
        $table->string('name', 255);
        $table->json('title');
        $table->json('body');
        $table->string('channels', 255)->default('in_app');
        $table->timestamps();
    });
}

echo "Ticket and notification tables created.\n";
