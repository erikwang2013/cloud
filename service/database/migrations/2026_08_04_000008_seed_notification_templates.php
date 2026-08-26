<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Schema-tolerant: install.sql creates notification_templates with
        // (name, title JSON, body JSON, channels VARCHAR); migration 0009 created
        // an alternative shape (title_template/body_template JSON, channels JSON).
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
                $payload['id'] = \Common\snowflake\SnowflakeService::nextId();
            }

            Capsule::table('notification_templates')->updateOrInsert(['code' => $code], $payload);
        }
    }

    public function down(): void
    {
        Capsule::table('notification_templates')->whereIn('code', [
            'ssl_cert_issued', 'ssl_cert_renewed', 'ssl_cert_expiring', 'ssl_cert_renewal_failed',
            'resource_suspended', 'resource_reactivated', 'rating_received',
            'affiliate_earning_credited', 'affiliate_payout_processed',
        ])->delete();
    }

    /**
     * [code, name, title(en/zh), body(en/zh), channels]
     */
    private function templates(): array
    {
        return [
            ['ssl_cert_issued', 'SSL Certificate Issued', [
                'en' => 'SSL Certificate Issued',
                'zh' => 'SSL 证书已签发',
            ], [
                'en' => 'Your SSL certificate for {{domain}} has been issued.',
                'zh' => '您的 {{domain}} SSL 证书已签发。',
            ], 'in_app,email'],

            ['ssl_cert_renewed', 'SSL Certificate Renewed', [
                'en' => 'SSL Certificate Renewed',
                'zh' => 'SSL 证书已续期',
            ], [
                'en' => 'Your SSL certificate for {{domain}} has been renewed.',
                'zh' => '您的 {{domain}} SSL 证书已续期。',
            ], 'in_app,email'],

            ['ssl_cert_expiring', 'SSL Certificate Expiring', [
                'en' => 'SSL Certificate Expiring',
                'zh' => 'SSL 证书即将到期',
            ], [
                'en' => 'Your SSL certificate for {{domain}} expires in {{days_left}} days.',
                'zh' => '您的 {{domain}} SSL 证书将在 {{days_left}} 天后到期。',
            ], 'in_app,email'],

            ['ssl_cert_renewal_failed', 'SSL Renewal Failed', [
                'en' => 'SSL Renewal Failed',
                'zh' => 'SSL 续期失败',
            ], [
                'en' => 'Automatic renewal failed for {{domain}}. Please renew manually.',
                'zh' => '{{domain}} 的自动续期失败，请手动续期。',
            ], 'in_app,email'],

            ['resource_suspended', 'Resource Suspended', [
                'en' => 'Resource Suspended',
                'zh' => '资源已暂停',
            ], [
                'en' => 'Resource #{{resource_id}} has been suspended due to insufficient balance.',
                'zh' => '资源 #{{resource_id}} 因余额不足已暂停。',
            ], 'in_app,email'],

            ['resource_reactivated', 'Resource Reactivated', [
                'en' => 'Resource Reactivated',
                'zh' => '资源已恢复',
            ], [
                'en' => 'Resource #{{resource_id}} has been reactivated.',
                'zh' => '资源 #{{resource_id}} 已恢复。',
            ], 'in_app,email'],

            ['rating_received', 'New Supplier Rating', [
                'en' => 'New Supplier Rating',
                'zh' => '收到新评分',
            ], [
                'en' => 'You received a {{rating}}-star rating on your supplier profile.',
                'zh' => '您的供应商资料收到 {{rating}} 星评分。',
            ], 'in_app,email'],

            ['affiliate_earning_credited', 'Commission Earned', [
                'en' => 'Commission Earned',
                'zh' => '佣金已入账',
            ], [
                'en' => 'You earned {{amount}} {{currency}} in affiliate commissions.',
                'zh' => '您获得 {{amount}} {{currency}} 联盟佣金。',
            ], 'in_app,email'],

            ['affiliate_payout_processed', 'Payout Processed', [
                'en' => 'Payout Processed',
                'zh' => '提现已处理',
            ], [
                'en' => 'Your affiliate payout of {{amount}} {{currency}} has been processed.',
                'zh' => '您的 {{amount}} {{currency}} 联盟提现已处理完成。',
            ], 'in_app,email'],
        ];
    }
};
