<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

/**
 * Seed the notification templates that were dispatched by code but missing
 * from the seed data, causing NotificationDispatcher to silently return
 * (auth, expiry and monitoring notifications never reached users).
 *
 * Idempotent (updateOrInsert on code) and schema-tolerant, matching the
 * model's title/body JSON map format.
 */
return new class extends Migration {
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $legacy = Capsule::schema()->hasColumn('notification_templates', 'title');
        $alt    = Capsule::schema()->hasColumn('notification_templates', 'title_template');

        foreach ($this->templates() as [$code, $name, $title, $body, $channels]) {
            $payload = ['name' => $name, 'created_at' => $now, 'updated_at' => $now];

            if ($legacy) {
                $payload['title']    = json_encode($title, JSON_UNESCAPED_UNICODE);
                $payload['body']     = json_encode($body, JSON_UNESCAPED_UNICODE);
                $payload['channels'] = $channels;
            } elseif ($alt) {
                $payload['title_template'] = json_encode($title, JSON_UNESCAPED_UNICODE);
                $payload['body_template']  = json_encode($body, JSON_UNESCAPED_UNICODE);
                $payload['channels']       = json_encode(explode(',', $channels));
            } else {
                throw new \RuntimeException('notification_templates: neither title nor title_template column found');
            }

            // install.sql 的 id 列无 AUTO_INCREMENT：legacy schema 首插必须显式提供 Snowflake 主键
            if ($legacy && !Capsule::table('notification_templates')->where('code', $code)->exists()) {
                $payload['id'] = \Common\Snowflake\SnowflakeService::nextId();
            }

            Capsule::table('notification_templates')->updateOrInsert(['code' => $code], $payload);
        }
    }

    public function down(): void
    {
        // Remove only the templates this migration introduced; the original
        // nine belong to 2026_08_04_000008_seed_notification_templates.
        Capsule::table('notification_templates')->whereIn('code', [
            'email_verify', 'new_ip_login', 'password_reset',
            'resource_expiring', 'domain_expiring', 'ssl_expiring',
            'alert_server_down', 'alert_cpu_high', 'alert_disk_high',
            'alert_ssl_expiring', 'alert_domain_expiring', 'alert_provision_failed',
            'alert_oncall',
        ])->delete();
    }

    /**
     * [code, name, title(en/zh), body(en/zh), channels]
     */
    private function templates(): array
    {
        return [
            // --- 认证 ---
            ['email_verify', 'Verify Your Email', [
                'en' => 'Verify Your Email',
                'zh' => '验证您的邮箱',
            ], [
                'en' => 'Click the link below to verify your email address: {{verify_url}}',
                'zh' => '点击以下链接验证您的邮箱地址：{{verify_url}}',
            ], 'in_app,email'],

            ['new_ip_login', 'New Sign-in Detected', [
                'en' => 'New Sign-in Detected',
                'zh' => '检测到新登录',
            ], [
                'en' => 'A new sign-in was detected from IP {{ip}} at {{time}}. If this was not you, please secure your account.',
                'zh' => '检测到新设备从 IP {{ip}} 于 {{time}} 登录。如非本人操作，请立即保护您的账户。',
            ], 'in_app,email'],

            ['password_reset', 'Password Reset Code', [
                'en' => 'Password Reset Code',
                'zh' => '密码重置验证码',
            ], [
                'en' => 'Your password reset code is {{code}}. It expires in 10 minutes.',
                'zh' => '您的密码重置验证码为 {{code}}，10 分钟内有效。',
            ], 'in_app,email'],

            // --- 到期提醒 ---
            ['resource_expiring', 'Resource Expiring Soon', [
                'en' => 'Resource Expiring Soon',
                'zh' => '资源即将到期',
            ], [
                'en' => 'Resource #{{resource_id}} ({{resource_type}}) will expire on {{expired_at}} ({{days_left}} days left).',
                'zh' => '资源 #{{resource_id}}（{{resource_type}}）将于 {{expired_at}} 到期，剩余 {{days_left}} 天。',
            ], 'in_app,email'],

            ['domain_expiring', 'Domain Expiring Soon', [
                'en' => 'Domain Expiring Soon',
                'zh' => '域名即将到期',
            ], [
                'en' => 'Your domain {{domain}} will expire on {{expires_at}} ({{days_left}} days left).',
                'zh' => '您的域名 {{domain}} 将于 {{expires_at}} 到期，剩余 {{days_left}} 天。',
            ], 'in_app,email'],

            ['ssl_expiring', 'SSL Certificate Expiring', [
                'en' => 'SSL Certificate Expiring',
                'zh' => 'SSL 证书即将到期',
            ], [
                'en' => 'Your SSL certificate for {{domain}} expires in {{days_left}} days.',
                'zh' => '您的 {{domain}} SSL 证书将在 {{days_left}} 天后到期。',
            ], 'in_app,email'],

            // --- 监控告警（AlertEngine）---
            ['alert_server_down', 'Server Down Alert', [
                'en' => 'Server Down Alert',
                'zh' => '服务器宕机告警',
            ], [
                'en' => 'Server {{resource_type}} is down ({{consecutive_checks}} consecutive failed checks).',
                'zh' => '服务器 {{resource_type}} 宕机（连续 {{consecutive_checks}} 次检测失败）。',
            ], 'in_app,email,sms'],

            ['alert_cpu_high', 'High CPU Alert', [
                'en' => 'High CPU Alert',
                'zh' => 'CPU 负载告警',
            ], [
                'en' => 'High CPU usage detected on {{resource_type}}.',
                'zh' => '{{resource_type}} 检测到高 CPU 负载。',
            ], 'in_app,email'],

            ['alert_disk_high', 'High Disk Usage Alert', [
                'en' => 'High Disk Usage Alert',
                'zh' => '磁盘使用告警',
            ], [
                'en' => 'High disk usage detected on {{resource_type}}.',
                'zh' => '{{resource_type}} 检测到磁盘使用率过高。',
            ], 'in_app,email'],

            ['alert_ssl_expiring', 'SSL Expiry Alert', [
                'en' => 'SSL Expiry Alert',
                'zh' => 'SSL 到期告警',
            ], [
                'en' => 'SSL certificate for {{domain}} on {{resource_type}} expires in {{days_left}} days.',
                'zh' => '{{resource_type}} 上的 {{domain}} SSL 证书将在 {{days_left}} 天后到期。',
            ], 'in_app,email'],

            ['alert_domain_expiring', 'Domain Expiry Alert', [
                'en' => 'Domain Expiry Alert',
                'zh' => '域名到期告警',
            ], [
                'en' => 'Domain {{domain}} on {{resource_type}} is expiring soon.',
                'zh' => '{{resource_type}} 上的域名 {{domain}} 即将到期。',
            ], 'in_app,email'],

            ['alert_provision_failed', 'Provisioning Failed', [
                'en' => 'Provisioning Failed',
                'zh' => '交付失败告警',
            ], [
                'en' => 'Provisioning task #{{task_id}} (order #{{order_id}}) failed: {{last_error}}',
                'zh' => '交付任务 #{{task_id}}（订单 #{{order_id}}）失败：{{last_error}}',
            ], 'in_app,email,sms'],

            ['alert_oncall', 'On-call Alert', [
                'en' => 'On-call Alert',
                'zh' => '值班告警',
            ], [
                'en' => 'Alert {{rule_code}} on resource {{resource_id}}.',
                'zh' => '资源 {{resource_id}} 触发告警 {{rule_code}}。',
            ], 'sms'],
        ];
    }
};
