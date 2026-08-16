<?php

namespace Tests\Common;

use PHPUnit\Framework\TestCase;

/**
 * Feature flags 配置契约测试：业务代码引用的 flag 必须存在于
 * config/features.php，否则开关静默失效（isEnabled 返回 null → false）。
 */
final class FeatureFlagsTest extends TestCase
{
    /** @return array<string, bool> */
    private function config(): array
    {
        $file = __DIR__ . '/../../config/features.php';
        $this->assertFileExists($file);
        return require $file;
    }

    public function testAllBusinessReferencedFlagsExist(): void
    {
        $flags = $this->config();

        $referenced = [
            'maintenance_redirect',
            'totp_two_factor',
            'google_oauth', 'apple_oauth', 'facebook_oauth', 'x_oauth',
            'microsoft_oauth', 'linkedin_oauth', 'github_oauth',
            'affiliate_program',
            'websocket_push',
            'graphql_api',
            'prometheus_metrics',
        ];

        foreach ($referenced as $name) {
            $this->assertArrayHasKey($name, $flags, "flag '{$name}' referenced in business code but missing in config/features.php");
        }
    }

    public function testAllFlagValuesAreBooleans(): void
    {
        foreach ($this->config() as $name => $value) {
            $this->assertIsBool($value, "flag '{$name}' must be boolean, got " . gettype($value));
        }
    }
}
