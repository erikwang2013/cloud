<?php
namespace Common\Auth;

class Rbac
{
    private static array $permissions = [
        'super_admin' => ['*'],
        'admin' => [
            'user.view', 'user.update', 'user.kyc_review',
            'product.create', 'product.update', 'product.delete', 'product.review',
            'order.view', 'order.update', 'order.refund',
            'payment.view', 'payment.channel_config',
            'resource.view', 'resource.destroy', 'provider.config',
            'supplier.view', 'supplier.review', 'supplier.settle',
            'ticket.view', 'ticket.assign', 'ticket.reply',
            'notification.template', 'notification.send',
            'report.view', 'report.export',
            'system.config',
        ],
        'finance' => [
            'order.view', 'order.refund',
            'payment.view', 'payment.channel_config', 'payment.reconcile',
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

    public function hasPermission(string $role, string $permission): bool
    {
        $perms = self::$permissions[$role] ?? [];
        if (in_array('*', $perms)) return true;
        return in_array($permission, $perms);
    }
}
