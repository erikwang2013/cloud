<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $templates = [
            ['ssl_cert_issued', 'SSL Certificate Issued', 'Your SSL certificate for {{domain}} has been issued.'],
            ['ssl_cert_renewed', 'SSL Certificate Renewed', 'Your SSL certificate for {{domain}} has been renewed.'],
            ['ssl_cert_expiring', 'SSL Certificate Expiring', 'Your SSL certificate for {{domain}} expires in {{days_left}} days.'],
            ['ssl_cert_renewal_failed', 'SSL Renewal Failed', 'Automatic renewal failed for {{domain}}. Please renew manually.'],
            ['resource_suspended', 'Resource Suspended', 'Resource #{{resource_id}} has been suspended due to insufficient balance.'],
            ['resource_reactivated', 'Resource Reactivated', 'Resource #{{resource_id}} has been reactivated.'],
            ['rating_received', 'New Supplier Rating', 'You received a {{rating}}-star rating on your supplier profile.'],
            ['affiliate_earning_credited', 'Commission Earned', 'You earned {{amount}} {{currency}} in affiliate commissions.'],
            ['affiliate_payout_processed', 'Payout Processed', 'Your affiliate payout of {{amount}} {{currency}} has been processed.'],
        ];

        foreach ($templates as [$code, $subject, $body]) {
            Capsule::table('notification_templates')->updateOrInsert(
                ['code' => $code],
                ['name' => $subject, 'subject' => $subject, 'body' => $body, 'created_at' => $now, 'updated_at' => $now]
            );
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
};
