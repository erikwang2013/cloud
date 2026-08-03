<?php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

// wa_roles
Capsule::schema()->create('roles', function (Blueprint $table) {
    $table->id();
    $table->string('name', 50)->unique();
    $table->string('display_name', 100);
    $table->text('description')->nullable();
    $table->timestamps();
});

// wa_permissions
Capsule::schema()->create('permissions', function (Blueprint $table) {
    $table->id();
    $table->string('name', 100)->unique(); // e.g. order.view, resource.destroy
    $table->string('display_name', 100);
    $table->string('group', 50); // module name
    $table->timestamps();
});

// wa_role_permission (pivot)
Capsule::schema()->create('role_permission', function (Blueprint $table) {
    $table->unsignedBigInteger('role_id');
    $table->unsignedBigInteger('permission_id');
    $table->primary(['role_id', 'permission_id']);
    $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
    $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
});

// Seed default roles
$roles = [
    [1, 'super_admin', 'Super Admin', 'Full access to all features'],
    [2, 'admin', 'Admin', 'Manage platform operations'],
    [3, 'finance', 'Finance', 'Payment/reconciliation/settlement'],
    [4, 'support', 'Support', 'User/order/ticket management'],
    [5, 'supplier', 'Supplier', 'Own products/orders/settlements'],
];
foreach ($roles as [$id, $name, $display, $desc]) {
    Capsule::table('roles')->insert(['id' => $id, 'name' => $name, 'display_name' => $display, 'description' => $desc]);
}

// Seed default permissions
$permissions = [
    ['user.view', 'View Users', 'user'],
    ['user.update', 'Update Users', 'user'],
    ['user.kyc', 'Manage KYC', 'user'],
    ['product.create', 'Create Products', 'product'],
    ['product.update', 'Update Products', 'product'],
    ['product.delete', 'Delete Products', 'product'],
    ['order.view', 'View Orders', 'order'],
    ['order.refund', 'Refund Orders', 'order'],
    ['payment.manage', 'Manage Payments', 'payment'],
    ['payment.reconcile', 'Reconcile Payments', 'payment'],
    ['resource.view', 'View Resources', 'resource'],
    ['resource.manage', 'Manage Resources', 'resource'],
    ['resource.destroy', 'Destroy Resources', 'resource'],
    ['supplier.view', 'View Suppliers', 'supplier'],
    ['supplier.approve', 'Approve Suppliers', 'supplier'],
    ['supplier.settle', 'Settle Suppliers', 'supplier'],
    ['ticket.manage', 'Manage Tickets', 'ticket'],
    ['system.config', 'System Config', 'system'],
    ['system.audit', 'View Audit Logs', 'system'],
    ['report.view', 'View Reports', 'report'],
];
foreach ($permissions as $i => [$name, $display, $group]) {
    Capsule::table('permissions')->insert(['id' => $i + 1, 'name' => $name, 'display_name' => $display, 'group' => $group]);
}

// Assign all permissions to super_admin
for ($i = 1; $i <= count($permissions); $i++) {
    Capsule::table('role_permission')->insert(['role_id' => 1, 'permission_id' => $i]);
}

// Admin role gets most permissions (exclude system.config, system.audit)
$adminPerms = array_diff(range(1, count($permissions)), [17, 18]);
foreach ($adminPerms as $pid) {
    Capsule::table('role_permission')->insert(['role_id' => 2, 'permission_id' => $pid]);
}

// Finance role
foreach ([6, 7, 8, 9, 10, 13, 14, 15, 16, 20] as $pid) {
    Capsule::table('role_permission')->insert(['role_id' => 3, 'permission_id' => $pid]);
}

// Support role
foreach ([1, 4, 6, 12, 17] as $pid) {
    Capsule::table('role_permission')->insert(['role_id' => 4, 'permission_id' => $pid]);
}

// Supplier role (limited to own data via model scope)
foreach ([5, 6, 11, 13] as $pid) {
    Capsule::table('role_permission')->insert(['role_id' => 5, 'permission_id' => $pid]);
}

echo "RBAC permissions seeded.\n";
