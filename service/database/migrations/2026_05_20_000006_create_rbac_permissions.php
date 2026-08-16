<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

/**
 * RBAC 表结构（种子数据见 2026_08_17_000001_seed_rbac_permissions.php）。
 * 与 install.sql roles/permissions/role_permission DDL 对齐。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Capsule::schema()->hasTable('roles')) {
            Capsule::schema()->create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 50)->unique();
                $table->string('display_name', 100);
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Capsule::schema()->hasTable('permissions')) {
            Capsule::schema()->create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique(); // e.g. order.view, resource.destroy
                $table->string('display_name', 100);
                $table->string('group', 50); // module name
                $table->timestamps();
            });
        }

        if (!Capsule::schema()->hasTable('role_permission')) {
            Capsule::schema()->create('role_permission', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('permission_id');
                $table->primary(['role_id', 'permission_id']);
                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
                $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (Capsule::schema()->hasTable('role_permission')) {
            Capsule::schema()->drop('role_permission');
        }
        if (Capsule::schema()->hasTable('permissions')) {
            Capsule::schema()->drop('permissions');
        }
        if (Capsule::schema()->hasTable('roles')) {
            Capsule::schema()->drop('roles');
        }
    }
};
