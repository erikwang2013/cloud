<?php

namespace Tests\Auth;

use Common\Auth\Rbac;
use PHPUnit\Framework\TestCase;

/**
 * 回归护栏：RBAC 种子迁移必须镜像 Common\Auth\Rbac（运行时唯一事实源），
 * 防止 Rbac.php 与 DB 权限模型漂移（P3.2 收敛）。
 * 纯静态分析，不依赖数据库（同 NotificationTemplateSeedTest 模式）。
 */
final class RbacSeedTest extends TestCase
{
    private const SEED_MIGRATION = __DIR__ . '/../../database/migrations/2026_08_17_000001_seed_rbac_permissions.php';

    /** Rbac::$permissions 全角色权限并集（不含 '*' 通配） */
    private function rbacUnion(array $rbac): array
    {
        $union = [];
        foreach ($rbac as $perms) {
            foreach ($perms as $p) {
                if ($p !== '*') {
                    $union[] = $p;
                }
            }
        }
        return array_values(array_unique($union));
    }

    /** 解析种子迁移：权限行 [name, display, group] 与角色分配 role => [perms] */
    private function parseSeed(): array
    {
        $src = file_get_contents(self::SEED_MIGRATION);

        preg_match_all("/\[\s*'([a-z0-9_.]+)'\s*,\s*'[^']+'\s*,\s*'[^']+'\s*\]/", $src, $permMatches);
        $permissions = array_values(array_unique($permMatches[1]));

        preg_match_all("/'([a-z0-9_]+)'\s*=>\s*\[([^\]]*)\]/", $src, $roleMatches);
        $rolePerms = [];
        foreach ($roleMatches[1] as $i => $role) {
            preg_match_all("/'([a-z0-9_.*]+)'/", $roleMatches[2][$i], $names);
            $rolePerms[$role] = $names[1];
        }

        return [$permissions, $rolePerms];
    }

    public function testSeedPermissionsMirrorRbacExactly(): void
    {
        $reflection = new \ReflectionClass(Rbac::class);
        $rbac = $reflection->getProperty('permissions')->getValue(new Rbac());

        [$seeded] = $this->parseSeed();

        sort($seeded);
        $union = $this->rbacUnion($rbac);
        sort($union);

        $this->assertSame(
            $union,
            $seeded,
            '种子权限集合必须与 Rbac::$permissions 并集完全一致（无遗漏、无死权限）'
        );
    }

    public function testSeedRoleAssignmentsMirrorRbac(): void
    {
        $reflection = new \ReflectionClass(Rbac::class);
        $rbac = $reflection->getProperty('permissions')->getValue(new Rbac());

        [, $rolePerms] = $this->parseSeed();

        $expectedRoles = array_keys($rbac);
        sort($expectedRoles);
        $seededRoles = array_keys($rolePerms);
        sort($seededRoles);
        $this->assertSame($expectedRoles, $seededRoles, '种子角色集合必须与 Rbac.php 一致');

        foreach ($rbac as $role => $perms) {
            if ($perms === ['*']) {
                // super_admin：Rbac 侧为通配，种子侧镜像同为 ['*']（展开发生在迁移 up() 时）
                $this->assertSame(['*'], $rolePerms[$role], "角色 {$role} 应保持通配镜像");
                continue;
            }
            $this->assertSame($perms, $rolePerms[$role], "角色 {$role} 的权限分配与 Rbac.php 不一致");
        }
    }

    public function testNoObsoletePermissionsSeeded(): void
    {
        [$seeded] = $this->parseSeed();

        $obsolete = ['user.kyc', 'payment.manage', 'resource.manage', 'supplier.approve', 'ticket.manage', 'system.audit'];
        foreach ($obsolete as $name) {
            $this->assertNotContains($name, $seeded, "废弃权限 {$name} 不应再出现在种子中");
        }
    }
}
