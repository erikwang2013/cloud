<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests;

use app\controller\DashboardController;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;
use support\Db;
use support\Response;
use tests\Support\RequestMock;

/**
 * Dashboard 统计：today_orders / today_revenue / pending_orders / active_resources。
 * 内存 sqlite + 在 PDO 上注册 VERSION() 函数，覆盖 Util::db()->select('select VERSION()') 路径。
 * 注意：sqlite 无 DECIMAL，SUM 返回整数；MySQL 下 DECIMAL 字符串形态（"0"/"123.4500"）需 MySQL 冒烟验证。
 */
final class DashboardControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'mysql');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema('mysql');
        $schema->create('wa_users', function ($t) {
            $t->integer('id');
            $t->timestamp('created_at')->nullable();
        });
        $schema->create('orders', function ($t) {
            $t->integer('id');
            $t->string('status');
            $t->integer('total')->default(0);
            // orders.exchange_rate = 币种→USD 汇率（USD=1.0），今日营收按此折算为 USD 基准
            $t->integer('exchange_rate')->default(1);
            $t->timestamp('paid_at')->nullable();
        });
        $schema->create('resources', function ($t) {
            $t->integer('id');
            $t->string('status');
        });

        // setAsGlobal 后 support\Db 与模型共用同一连接；注册 VERSION() 供系统信息查询
        Db::connection('mysql')->getPdo()->sqliteCreateFunction('VERSION', static fn () => '8.0.99-test');
    }

    private function db(): Connection
    {
        return Capsule::connection('mysql');
    }

    private function ts(int $daysAgo, string $time = '12:00:00'): string
    {
        return date('Y-m-d ' . $time, time() - $daysAgo * 86400);
    }

    /** @return array{stats: array, user_trend_7d: array, user_trend_30d: array, system: array} */
    private function stats(): array
    {
        $response = (new DashboardController())->index(new RequestMock());
        $decoded = json_decode((string) $response->rawBody(), true);
        $this->assertSame(0, $decoded['code'] ?? -1, 'expected success response');
        $this->assertInstanceOf(Response::class, $response);
        return $decoded['data'];
    }

    public function testStatsWithData(): void
    {
        $this->db()->table('wa_users')->insert([
            ['id' => 1, 'created_at' => $this->ts(0, '08:00:00')],
            ['id' => 2, 'created_at' => $this->ts(0, '09:00:00')],
            ['id' => 3, 'created_at' => $this->ts(1, '08:00:00')],
        ]);
        $this->db()->table('orders')->insert([
            ['id' => 1, 'status' => 'paid', 'total' => 100, 'paid_at' => $this->ts(0, '10:00:00')],
            ['id' => 2, 'status' => 'paid', 'total' => 50, 'paid_at' => $this->ts(0, '11:00:00')],
            ['id' => 3, 'status' => 'refunded', 'total' => 999, 'paid_at' => $this->ts(0, '12:00:00')],
            ['id' => 4, 'status' => 'pending', 'total' => 30, 'paid_at' => null],
            ['id' => 5, 'status' => 'paid', 'total' => 200, 'paid_at' => $this->ts(1, '10:00:00')],
        ]);
        $this->db()->table('resources')->insert([
            ['id' => 1, 'status' => 'active'],
            ['id' => 2, 'status' => 'active'],
            ['id' => 3, 'status' => 'suspended'],
        ]);

        $data = $this->stats();
        $stats = $data['stats'];

        $this->assertSame(2, $stats['today_orders'], '今日已付且非退款');
        $this->assertSame(150, $stats['today_revenue'], 'refunded 不计入营收');
        $this->assertSame(1, $stats['pending_orders']);
        $this->assertSame(2, $stats['active_resources']);

        // 回归：原有用户统计与趋势不受影响
        $this->assertSame(2, $stats['today_users']);
        $this->assertSame(3, $stats['week_users']);
        $this->assertSame(3, $stats['month_users']);
        $this->assertSame(3, $stats['total_users']);
        $this->assertCount(7, $data['user_trend_7d']);
        $this->assertCount(30, $data['user_trend_30d']);

        // VERSION() 注册生效：系统信息路径未被破坏
        $this->assertSame('8.0.99-test', $data['system']['mysql_version']);
    }

    public function testStatsEmptyDatabase(): void
    {
        $data = $this->stats();
        $stats = $data['stats'];

        $this->assertSame(0, $stats['today_orders']);
        $this->assertSame(0, $stats['today_revenue'], '无数据时必须为 0 而非 null');
        $this->assertNotSame(null, $stats['today_revenue']);
        $this->assertSame(0, $stats['pending_orders']);
        $this->assertSame(0, $stats['active_resources']);
        $this->assertSame(0, $stats['today_users']);
        $this->assertCount(7, $data['user_trend_7d']);
        $this->assertCount(30, $data['user_trend_30d']);
    }
}
