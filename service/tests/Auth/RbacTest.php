<?php

namespace Tests\Auth;

use Common\Auth\Rbac;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RbacTest extends TestCase
{
    private Rbac $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rbac = new Rbac();
    }

    #[DataProvider('permissionMatrixProvider')]
    public function testPermissionMatrix(string $role, string $permission, bool $expected): void
    {
        $this->assertSame($expected, $this->rbac->hasPermission($role, $permission));
    }

    public static function permissionMatrixProvider(): array
    {
        return [
            // super_admin wildcard
            'super_admin anything' => ['super_admin', 'any.thing.at.all', true],
            'super_admin user.view' => ['super_admin', 'user.view', true],

            // admin
            'admin user.view' => ['admin', 'user.view', true],
            'admin product.delete' => ['admin', 'product.delete', true],
            'admin order.refund' => ['admin', 'order.refund', true],
            'admin system.config' => ['admin', 'system.config', true],
            'admin reconcile payments' => ['admin', 'payment.reconcile', true],
            'admin withdraw review' => ['admin', 'supplier.withdraw_review', true],
            'admin coupon manage' => ['admin', 'coupon.manage', true],

            // finance — money paths only
            'finance payment.reconcile' => ['finance', 'payment.reconcile', true],
            'finance supplier.settle' => ['finance', 'supplier.settle', true],
            'finance order.refund' => ['finance', 'order.refund', true],
            'finance cannot edit users' => ['finance', 'user.update', false],
            'finance cannot create products' => ['finance', 'product.create', false],
            'finance cannot manage system config' => ['finance', 'system.config', false],
            'finance cannot manage coupons' => ['finance', 'coupon.manage', false],

            // support — read-only ticket/user
            'support ticket.reply' => ['support', 'ticket.reply', true],
            'support user.view' => ['support', 'user.view', true],
            'support cannot refund' => ['support', 'order.refund', false],
            'support cannot edit users' => ['support', 'user.update', false],
            'support cannot manage coupons' => ['support', 'coupon.manage', false],

            // supplier — own-scope only
            'supplier product.create' => ['supplier', 'product.create', true],
            'supplier settle_view' => ['supplier', 'supplier.settle_view', true],
            'supplier cannot see all orders' => ['supplier', 'order.view', false],
            'supplier cannot settle' => ['supplier', 'supplier.settle', false],

            // user role has no permissions
            'user nothing' => ['user', 'user.view', false],
            'user no order view' => ['user', 'order.view', false],

            // unknown role
            'unknown role denied' => ['root', 'system.config', false],
            'empty role denied' => ['', 'user.view', false],
        ];
    }
}
