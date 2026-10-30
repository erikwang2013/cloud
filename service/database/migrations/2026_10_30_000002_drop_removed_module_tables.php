<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

/**
 * 清理已下线模块（SSL 证书 / 对象存储 / 按量计费 / CDN / 联盟分销）的遗留表与通知模板。
 * 对应建表迁移已在 2026-10-30 删除，新装库不会创建这些表；已部署库由本迁移收口。
 * 安全约束：仅当表为空时 DROP；非空表保留并输出提示，交由人工确认归档，绝不自动删数据。
 */
return new class extends Migration {
    private const TABLES = [
        'ssl_plans', 'resource_ssl_certs',
        'resource_storage_buckets',
        'resource_metrics', 'usage_events', 'usage_rates', 'usage_invoice_items',
        'resource_cdn',
        'affiliate_plans', 'affiliate_links', 'affiliate_earnings', 'affiliate_payouts',
    ];

    // 仅由已下线模块生产的模板（ssl_expiring / alert_ssl_expiring 仍由监控探针使用，保留）
    private const DEAD_TEMPLATES = [
        'ssl_cert_issued', 'ssl_cert_renewed', 'ssl_cert_expiring', 'ssl_cert_renewal_failed',
        'affiliate_earning_credited', 'affiliate_payout_processed',
        'resource_suspended', 'resource_reactivated',
    ];

    public function up(): void
    {
        $schema = Capsule::schema();
        $dropped = [];
        $kept = [];

        foreach (self::TABLES as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            $rows = Capsule::table($table)->count();
            if ($rows > 0) {
                $kept[] = "{$table} ({$rows} rows)";
                continue;
            }
            $schema->drop($table);
            $dropped[] = $table;
        }

        if ($schema->hasTable('notification_templates')) {
            Capsule::table('notification_templates')->whereIn('code', self::DEAD_TEMPLATES)->delete();
        }

        if ($dropped) {
            echo 'Dropped empty tables: ' . implode(', ', $dropped) . PHP_EOL;
        }
        if ($kept) {
            echo 'KEPT non-empty tables (manual review required): ' . implode(', ', $kept) . PHP_EOL;
        }
    }

    public function down(): void
    {
        // 不可逆：已下线模块的表结构不再恢复。
    }
};
