<?php

namespace Tests\Notification;

use PHPUnit\Framework\TestCase;

/**
 * 回归护栏：代码中 dispatch 的每个通知模板 code 都必须存在种子定义，
 * 防止"模板缺失 → NotificationDispatcher 静默 return"问题复发。
 * 纯静态分析，不依赖数据库。
 */
final class NotificationTemplateSeedTest extends TestCase
{
    private const APP_DIR        = __DIR__ . '/../../app';
    private const MIGRATION_DIR  = __DIR__ . '/../../database/migrations';

    public function testEveryDispatchedCodeHasSeededTemplate(): void
    {
        $missing = array_values(array_diff($this->dispatchedCodes(), $this->seededCodes()));

        $this->assertSame(
            [],
            $missing,
            '以下 dispatch code 缺少种子模板（会静默失效）: ' . implode(', ', $missing)
        );
    }

    public function testEveryAlertRuleHasTemplate(): void
    {
        $seeded = $this->seededCodes();
        $rules  = $this->alertRuleCodes();

        $this->assertNotEmpty($rules, '未从 AlertEngine 解析出任何告警规则');

        foreach ($rules as $rule) {
            $this->assertContains('alert_' . $rule, $seeded, "缺少 alert_{$rule} 种子模板");
        }
        $this->assertContains('alert_oncall', $seeded, '缺少 alert_oncall 种子模板');
    }

    public function testSeedMigrationsAreParsed(): void
    {
        $seedFiles = glob(self::MIGRATION_DIR . '/*notification_template*.php') ?: [];
        $this->assertSame(2, count($seedFiles), '应找到 2 个通知模板种子迁移（000008 + 000001 补充）');

        $seeded = $this->seededCodes();
        $this->assertGreaterThanOrEqual(9, count($seeded), '种子模板数量异常，正则解析可能失效');
        $this->assertContains('email_verify', $seeded);
        $this->assertContains('ssl_expiring', $seeded);
        $this->assertContains('alert_oncall', $seeded);
    }

    public function testTemplatePlaceholdersMatchDispatchData(): void
    {
        // 每个模板 body 中的 {{placeholder}} 必须出现在对应 dispatch 调用点的 data 键中，
        // 防止占位符拼写不一致（如 days vs days_left）导致字面残留。
        $dispatchKeys = $this->dispatchDataKeysByCode();
        // AlertEngine 动态 'alert_' . $ruleCode 的合并上下文键（AlertEngine.php:57-62/77-80）
        $alertContextKeys = [
            'resource_type', 'consecutive_checks', 'domain', 'days_left',
            'task_id', 'order_id', 'last_error', 'resource_id', 'rule_code',
        ];

        foreach ($this->templatesWithBodies() as $code => $body) {
            preg_match_all('/\{\{([a-z0-9_]+)\}\}/', $body, $m);
            $placeholders = array_values(array_unique($m[1]));
            if (empty($placeholders)) {
                continue;
            }

            $keys = $dispatchKeys[$code] ?? [];
            if ($keys === [] && !str_starts_with($code, 'alert_')) {
                // 无 dispatch 调用点的休眠模板（如 ssl_cert_* / rating_received），
                // 无法静态校验占位符，跳过（仅对有调用点的活动路径拦截拼写回归）
                continue;
            }
            if ($keys === [] && str_starts_with($code, 'alert_')) {
                $keys = $alertContextKeys;
            }

            $unmatched = array_values(array_diff($placeholders, $keys));
            $this->assertSame(
                [],
                $unmatched,
                "模板 {$code} 的占位符在 dispatch 数据中无对应键（将残留字面量）: " . implode(', ', $unmatched)
            );
        }
    }

    /** 扫描 service/app 中所有硬编码的 dispatch code */
    private function dispatchedCodes(): array
    {
        $codes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::APP_DIR, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            // (new NotificationDispatcher())->dispatch($user->id, 'code', [...])
            // 排除 'alert_' . $ruleCode 这类动态拼接（由 testEveryAlertRuleHasTemplate 覆盖）
            if (preg_match_all("/->dispatch\(\s*[^,]+,\s*'([a-z0-9_]+)'(?!\s*\.)/", $src, $m)) {
                $codes = array_merge($codes, $m[1]);
            }
        }
        return array_values(array_unique($codes));
    }

    /** 解析 AlertEngine 的告警规则 code（alert_<rule> 动态派发） */
    private function alertRuleCodes(): array
    {
        $src = file_get_contents(self::APP_DIR . '/Monitor/Service/AlertEngine.php');
        preg_match_all("/'([a-z_]+)'\s*=>\s*\[\s*'severity'/", $src, $m);
        return $m[1];
    }

    /** 汇总两个种子迁移中定义的全部模板 code */
    private function seededCodes(): array
    {
        $codes = [];
        foreach (glob(self::MIGRATION_DIR . '/*notification_template*.php') ?: [] as $file) {
            $src = file_get_contents($file);
            // 匹配模板元组首元素：['code', 'Name', [...], [...]]
            if (preg_match_all("/\[\s*'([a-z0-9_]+)'\s*,\s*'/", $src, $m)) {
                $codes = array_merge($codes, $m[1]);
            }
        }
        return array_values(array_unique($codes));
    }

    /** 解析每个 dispatch code 对应的 data 键（多个调用点取并集） */
    private function dispatchDataKeysByCode(): array
    {
        $map = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::APP_DIR, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            preg_match_all(
                "/->dispatch\(\s*[^,]+,\s*'([a-z0-9_]+)'(?!\s*\.)\s*,\s*(?:array_merge\([^,]+,\s*)?\[(.*?)\]/s",
                $src,
                $m,
                PREG_SET_ORDER
            );
            foreach ($m as $hit) {
                $code = $hit[1];
                preg_match_all("/'([a-z0-9_]+)'\s*=>/", $hit[2], $keys);
                $map[$code] = array_merge($map[$code] ?? [], $keys[1]);
            }
        }
        return array_map('array_unique', $map);
    }

    /** 解析种子模板的 code => en body（用于占位符校验） */
    private function templatesWithBodies(): array
    {
        $result = [];
        foreach (glob(self::MIGRATION_DIR . '/*notification_template*.php') ?: [] as $file) {
            $src = file_get_contents($file);
            if (preg_match_all(
                "/\[\s*'([a-z0-9_]+)'\s*,\s*'[^']*'\s*,\s*\[[^\]]*\]\s*,\s*\[\s*'en'\s*=>\s*'([^']*)'/s",
                $src,
                $m,
                PREG_SET_ORDER
            )) {
                foreach ($m as $hit) {
                    $result[$hit[1]] = $hit[2];
                }
            }
        }
        return $result;
    }
}
