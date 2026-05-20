<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

Capsule::schema()->table('users', function (Blueprint $table) {
    if (!Capsule::schema()->hasColumn('users', 'totp_secret')) {
        $table->string('totp_secret', 255)->nullable()->after('password_hash');
    }
    if (!Capsule::schema()->hasColumn('users', 'totp_enabled')) {
        $table->boolean('totp_enabled')->default(false)->after('totp_secret');
    }
});

echo "Added TOTP columns to users table.\n";
