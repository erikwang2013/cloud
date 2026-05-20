<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

Capsule::schema()->table('users', function (Blueprint $table) {
    if (!Capsule::schema()->hasColumn('users', 'last_login_ip')) {
        $table->string('last_login_ip', 45)->nullable()->after('status');
    }
    if (!Capsule::schema()->hasColumn('users', 'last_login_at')) {
        $table->timestamp('last_login_at')->nullable()->after('last_login_ip');
    }
});

echo "Added login tracking columns to users table.\n";
