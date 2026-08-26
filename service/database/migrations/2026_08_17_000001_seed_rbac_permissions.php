<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

/**
 * RBAC 权限模型种子（收敛后唯一事实源：Common\auth\Rbac）。
 * - 权限行 = Rbac::$permissions 并集（含 supplier own-scope 变体）
 * - 角色分配 = Rbac::$permissions 逐角色矩阵
 * 对已跑过旧版 000006 的库执行 reset 式重种（先删后插），对全新库幂等。
 * 修改 Rbac.php 时必须同步本文件；tests/Auth/RbacSeedTest.php 静态拦截漂移。
 */
return new class extends Migration {
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // FK 约束下禁用 truncate，用 delete；权限/角色均显式 id，不受自增影响
        Capsule::table('role_permission')->delete();
        Capsule::table('permissions')->delete();
        Capsule::table('roles')->delete();

        $roles = [
            [1, 'super_admin', 'Super Admin', 'Full access to all features'],
            [2, 'admin', 'Admin', 'Manage platform operations'],
            [3, 'finance', 'Finance', 'Payment/reconciliation/settlement'],
            [4, 'support', 'Support', 'User/order/ticket management'],
            [5, 'supplier', 'Supplier', 'Own products/orders/settlements'],
        ];
        foreach ($roles as [$id, $name, $display, $desc]) {
            Capsule::table('roles')->insert([
                'id' => $id, 'name' => $name, 'display_name' => $display,
                'description' => $desc, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $permIds = [];
        foreach ($this->permissions() as $i => [$name, $display, $group]) {
            $permIds[$name] = $i + 1;
            Capsule::table('permissions')->insert([
                'id' => $permIds[$name], 'name' => $name, 'display_name' => $display,
                'group' => $group, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ($this->rolePerms() as $roleName => $permNames) {
            if ($permNames === ['*']) {
                $permNames = array_keys($permIds);
            }
            $roleId = (int) Capsule::table('roles')->where('name', $roleName)->value('id');
            foreach ($permNames as $permName) {
                Capsule::table('role_permission')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permIds[$permName],
                ]);
            }
        }
    }

    public function down(): void
    {
        Capsule::table('role_permission')->delete();
        Capsule::table('permissions')->delete();
        Capsule::table('roles')->delete();
    }

    /** [name, display_name, group] —— 与 Common\auth\Rbac::$permissions 并集一致 */
    private function permissions(): array
    {
        return [
            ['user.view', 'View Users', 'user'],
            ['user.update', 'Update Users', 'user'],
            ['user.kyc_review', 'Review KYC', 'user'],
            ['product.create', 'Create Products', 'product'],
            ['product.update', 'Update Products', 'product'],
            ['product.delete', 'Delete Products', 'product'],
            ['product.review', 'Review Products', 'product'],
            ['product.update_own', 'Update Own Products', 'product'],
            ['order.view', 'View Orders', 'order'],
            ['order.update', 'Update Orders', 'order'],
            ['order.refund', 'Refund Orders', 'order'],
            ['order.view_own', 'View Own Orders', 'order'],
            ['payment.view', 'View Payments', 'payment'],
            ['payment.channel_config', 'Configure Payment Channels', 'payment'],
            ['payment.reconcile', 'Reconcile Payments', 'payment'],
            ['resource.view', 'View Resources', 'resource'],
            ['resource.update', 'Update Resources', 'resource'],
            ['resource.destroy', 'Destroy Resources', 'resource'],
            ['provider.config', 'Configure Providers', 'provider'],
            ['supplier.view', 'View Suppliers', 'supplier'],
            ['supplier.review', 'Review Suppliers', 'supplier'],
            ['supplier.settle', 'Settle Suppliers', 'supplier'],
            ['supplier.withdraw_review', 'Review Supplier Withdraws', 'supplier'],
            ['supplier.settle_view', 'View Own Settlements', 'supplier'],
            ['ticket.view', 'View Tickets', 'ticket'],
            ['ticket.assign', 'Assign Tickets', 'ticket'],
            ['ticket.reply', 'Reply Tickets', 'ticket'],
            ['notification.template', 'Manage Notification Templates', 'notification'],
            ['notification.send', 'Send Notifications', 'notification'],
            ['report.view', 'View Reports', 'report'],
            ['report.export', 'Export Reports', 'report'],
            ['system.config', 'System Config', 'system'],
            ['help.manage', 'Manage Help Articles', 'content'],
            ['ssl.plan', 'Manage SSL Plans/Certs', 'ssl'],
            ['billing.rate', 'Manage Billing Rates', 'billing'],
            ['cdn.manage', 'Manage CDN Domains', 'cdn'],
            ['affiliate.approve', 'Approve Affiliate Earnings/Payouts', 'affiliate'],
            ['domain.tld', 'Manage Domain TLDs', 'domain'],
            ['domain.transfer_approve', 'Approve Domain Transfers', 'domain'],
            ['webhook.manage', 'Manage Webhooks', 'webhook'],
            ['coupon.manage', 'Manage Coupons', 'marketing'],
        ];
    }

    /** role => permission names —— 逐角色镜像 Common\auth\Rbac::$permissions */
    private function rolePerms(): array
    {
        return [
            'super_admin' => ['*'],
            'admin' => [
                'user.view', 'user.update', 'user.kyc_review',
                'product.create', 'product.update', 'product.delete', 'product.review',
                'order.view', 'order.update', 'order.refund',
                'payment.view', 'payment.channel_config', 'payment.reconcile',
                'resource.view', 'resource.update', 'resource.destroy', 'provider.config',
                'supplier.view', 'supplier.review', 'supplier.settle', 'supplier.withdraw_review',
                'ticket.view', 'ticket.assign', 'ticket.reply',
                'notification.template', 'notification.send',
                'report.view', 'report.export',
                'system.config',
                'help.manage', 'ssl.plan', 'billing.rate', 'cdn.manage',
                'affiliate.approve', 'domain.tld', 'domain.transfer_approve', 'webhook.manage',
                'coupon.manage',
            ],
            'finance' => [
                'order.view', 'order.refund',
                'payment.view', 'payment.reconcile',
                'supplier.settle', 'supplier.withdraw_review',
                'report.view', 'report.export',
            ],
            'support' => [
                'user.view',
                'order.view',
                'resource.view',
                'ticket.view', 'ticket.reply',
            ],
            'supplier' => [
                'product.create', 'product.update_own',
                'order.view_own',
                'supplier.settle_view',
            ],
            'user' => [],
        ];
    }
};
