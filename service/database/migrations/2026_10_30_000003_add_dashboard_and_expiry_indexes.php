<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use support\Migration;

/**
 * 1) users(created_at)：管理端 dashboard 近 30 天用户趋势按 created_at 分组查询。
 * 2) dns_zones(expires_at)：App\cron\ExpirationCheck 扫描域名到期；老库缺列时补列。
 * 两个索引均先判存在，可安全重跑。
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('users') && !$schema->hasIndex('users', 'idx_users_created_at')) {
            $schema->table('users', function (Blueprint $table) {
                $table->index('created_at', 'idx_users_created_at');
            });
        }

        if ($schema->hasTable('dns_zones')) {
            if (!$schema->hasColumn('dns_zones', 'expires_at')) {
                $schema->table('dns_zones', function (Blueprint $table) {
                    $table->dateTime('expires_at')->nullable()->after('domain_name');
                });
            }
            if (!$schema->hasIndex('dns_zones', 'idx_dns_zones_expires_at')) {
                $schema->table('dns_zones', function (Blueprint $table) {
                    $table->index('expires_at', 'idx_dns_zones_expires_at');
                });
            }
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('users') && $schema->hasIndex('users', 'idx_users_created_at')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropIndex('idx_users_created_at');
            });
        }
        // expires_at 列可能是本迁移补的，也可能本就存在，down 不删列避免误删存量数据。
        if ($schema->hasTable('dns_zones') && $schema->hasIndex('dns_zones', 'idx_dns_zones_expires_at')) {
            $schema->table('dns_zones', function (Blueprint $table) {
                $table->dropIndex('idx_dns_zones_expires_at');
            });
        }
    }
};
