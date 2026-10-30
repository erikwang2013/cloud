<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

/**
 * AUD-12：users.email_verify_expires_at 记录邮件验证令牌有效期（coder-auth 写入/校验）。
 * AUD-08：refunds.stripe_refund_id 持久化 Stripe 退款单号（coder-payments 写入），加索引便于对账查询。
 * 均先判存在，可安全重跑；down 不删列，避免回滚丢失已写入的令牌有效期/退款单号（列也可能由 install.sql 预建）。
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('users') && !$schema->hasColumn('users', 'email_verify_expires_at')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dateTime('email_verify_expires_at')->nullable()->after('email_verify_token');
            });
        }

        if ($schema->hasTable('refunds')) {
            if (!$schema->hasColumn('refunds', 'stripe_refund_id')) {
                $schema->table('refunds', function (Blueprint $table) {
                    $table->string('stripe_refund_id', 128)->nullable()->after('reason');
                });
            }
            if (!$schema->hasIndex('refunds', ['stripe_refund_id'])) {
                $schema->table('refunds', function (Blueprint $table) {
                    $table->index('stripe_refund_id', 'idx_stripe_refund_id');
                });
            }
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('refunds') && $schema->hasIndex('refunds', 'idx_stripe_refund_id')) {
            $schema->table('refunds', function (Blueprint $table) {
                $table->dropIndex('idx_stripe_refund_id');
            });
        }
    }
};
