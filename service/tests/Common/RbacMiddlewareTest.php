<?php
namespace Tests\Common;

use Common\Auth\Middleware\RbacMiddleware;
use Common\Auth\Rbac;
use PHPUnit\Framework\TestCase;

class RbacMiddlewareTest extends TestCase
{
    private function createRequest(?string $role)
    {
        return new class($role) {
            public ?string $userRole;
            public function __construct(?string $role) { $this->userRole = $role; }
        };
    }

    private function decodeResponse($response): array
    {
        if (is_string($response)) {
            return json_decode($response, true);
        }
        if (method_exists($response, 'rawBody')) {
            return json_decode($response->rawBody(), true);
        }
        return [];
    }

    private function invoke(RbacMiddleware $middleware, ?string $role, bool &$nextCalled)
    {
        return $middleware->process($this->createRequest($role), function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return 'next-result';
        });
    }

    public function testDeniedWithoutPermission(): void
    {
        $nextCalled = false;
        $response = $this->invoke(new RbacMiddleware('product.create'), 'support', $nextCalled);
        $body = $this->decodeResponse($response);
        $this->assertEquals(403, $body['code']);
        $this->assertFalse($nextCalled);
    }

    public function testAllowedWithPermission(): void
    {
        $nextCalled = false;
        $response = $this->invoke(new RbacMiddleware('product.create'), 'admin', $nextCalled);
        $this->assertTrue($nextCalled);
        $this->assertEquals('next-result', $response);
    }

    public function testSuperAdminWildcardAllowsAny(): void
    {
        $nextCalled = false;
        $response = $this->invoke(new RbacMiddleware('nonexistent.perm'), 'super_admin', $nextCalled);
        $this->assertTrue($nextCalled);
        $this->assertEquals('next-result', $response);
    }

    public function testGuestDenied(): void
    {
        $nextCalled = false;
        $response = $this->invoke(new RbacMiddleware('user.view'), null, $nextCalled);
        $body = $this->decodeResponse($response);
        $this->assertEquals(403, $body['code']);
        $this->assertFalse($nextCalled);
    }

    public function testUserRoleDenied(): void
    {
        $nextCalled = false;
        $response = $this->invoke(new RbacMiddleware('user.view'), 'user', $nextCalled);
        $body = $this->decodeResponse($response);
        $this->assertEquals(403, $body['code']);
        $this->assertFalse($nextCalled);
    }

    public function testFinanceGetsPaymentReconcile(): void
    {
        $nextCalled = false;
        $this->invoke(new RbacMiddleware('payment.reconcile'), 'finance', $nextCalled);
        $this->assertTrue($nextCalled);
    }

    public function testFinanceDeniedProductCreate(): void
    {
        $nextCalled = false;
        $response = $this->invoke(new RbacMiddleware('product.create'), 'finance', $nextCalled);
        $body = $this->decodeResponse($response);
        $this->assertEquals(403, $body['code']);
        $this->assertFalse($nextCalled);
    }

    public function testSupportGetsTicketView(): void
    {
        $nextCalled = false;
        $this->invoke(new RbacMiddleware('ticket.view'), 'support', $nextCalled);
        $this->assertTrue($nextCalled);
    }

    public function testRbacModelDirect(): void
    {
        $rbac = new Rbac();
        $this->assertTrue($rbac->hasPermission('admin', 'order.refund'));
        $this->assertFalse($rbac->hasPermission('support', 'payment.view'));
        $this->assertTrue($rbac->hasPermission('supplier', 'product.create'));
        $this->assertFalse($rbac->hasPermission('supplier', 'product.delete'));
        $this->assertTrue($rbac->hasPermission('super_admin', 'anything.at.all'));
        $this->assertFalse($rbac->hasPermission('unknown_role', 'user.view'));
    }

    public function testAllRouteMountPermissionsExistInModel(): void
    {
        $routeFile = dirname(__DIR__, 2) . '/config/route.php';
        $this->assertFileExists($routeFile);
        $source = file_get_contents($routeFile);

        preg_match_all("/new Common\\\\Auth\\\\Middleware\\\\RbacMiddleware\('([^']+)'\)/", $source, $matches);
        $mounts = $matches[1];
        $this->assertNotEmpty($mounts, 'route.php should mount at least one RbacMiddleware');

        $reflection = new \ReflectionClass(Rbac::class);
        $perms = $reflection->getProperty('permissions')->getValue(new Rbac());
        $valid = [];
        foreach ($perms as $list) {
            foreach ($list as $p) {
                $valid[$p] = true;
            }
        }
        $valid['*'] = true;

        foreach ($mounts as $permission) {
            $this->assertArrayHasKey($permission, $valid, "route.php mounts unknown permission: {$permission}");
        }
    }

    public function testEveryMountedPermissionReachableByAdminRole(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/config/route.php');
        preg_match_all("/new Common\\\\Auth\\\\Middleware\\\\RbacMiddleware\('([^']+)'\)/", $source, $matches);
        $mounts = array_unique($matches[1]);

        $reflection = new \ReflectionClass(Rbac::class);
        $perms = $reflection->getProperty('permissions')->getValue(new Rbac());

        // AdminRoleMiddleware ALLOWED_ROLES = admin/super_admin/support；
        // super_admin 为 '*' 通配，排除后检查非通配可达角色是否持有，防权限死锁
        $covered = array_merge($perms['admin'] ?? [], $perms['support'] ?? []);

        foreach ($mounts as $permission) {
            $this->assertContains($permission, $covered, "mounted permission {$permission} is unreachable by any AdminRole role");
        }
    }
}
