<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

Capsule::schema()->table('users', function (Blueprint $table) {
    if (!Capsule::schema()->hasColumn('users', 'email_verified_at')) {
        $table->timestamp('email_verified_at')->nullable()->after('email');
    }
    if (!Capsule::schema()->hasColumn('users', 'email_verify_token')) {
        $table->string('email_verify_token', 255)->nullable()->after('email_verified_at');
    }
});

echo "Added email verification columns to users table.\n";
