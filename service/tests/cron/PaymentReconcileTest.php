<?php

namespace Tests\cron;

use App\cron\PaymentReconcile;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

/**
 * 资金对账核心：两侧金额按币种最小单位精度（2 位/零小数币种 0 位）half-up
 * 舍入后逐币种比对，任一币种不一致即 mismatch；子分残余不计入 diff。
 */
final class PaymentReconcileTest extends TestCase
{
    public function testCompareVerifiedWithSubUnitResidue(): void
    {
        // 通道分精度 10.50 vs 本地 10.5049：舍入到分一致 → verified，diff 归零
        $result = PaymentReconcile::compare(['USD' => '10.50'], ['USD' => '10.5049']);

        $this->assertSame('verified', $result['status']);
        $this->assertSame('0', $result['diff']);
        $this->assertSame('10.5000', $result['channel_total']);
        $this->assertSame('10.5049', $result['system_total']);
    }

    public function testCompareMismatchWhenUnitPrecisionDiffers(): void
    {
        $result = PaymentReconcile::compare(['USD' => '10.50'], ['USD' => '10.51']);

        $this->assertSame('mismatch', $result['status']);
        $this->assertSame('-0.0100', $result['diff']);
    }

    public function testCompareZeroDecimalCurrencyRoundsToInteger(): void
    {
        // JPY 零小数币种：4 位小数残余舍入到整数，与通道整数一致 → verified
        $verified = PaymentReconcile::compare(['JPY' => '1234'], ['JPY' => '1234.4000']);
        $this->assertSame('verified', $verified['status']);
        $this->assertSame('0', $verified['diff']);

        // 0.5 进位到 1235 仍与通道 1234 不一致 → mismatch；diff 为原始总额差
        $mismatch = PaymentReconcile::compare(['JPY' => '1234'], ['JPY' => '1234.5000']);
        $this->assertSame('mismatch', $mismatch['status']);
        $this->assertSame('-0.5000', $mismatch['diff']);
    }

    public function testCompareMixedCurrenciesAccumulateTotalsPerSide(): void
    {
        $result = PaymentReconcile::compare(
            ['USD' => '10.00', 'CNY' => '100.00'],
            ['USD' => '10.00']
        );

        $this->assertSame('mismatch', $result['status'], 'CNY 仅存在于通道侧必须 mismatch');
        $this->assertSame('110.0000', $result['channel_total']);
        $this->assertSame('10.0000', $result['system_total']);
        $this->assertSame('100.0000', $result['diff']);
    }

    public function testCompareBothSidesEmptyIsVerified(): void
    {
        $result = PaymentReconcile::compare([], []);

        $this->assertSame('verified', $result['status']);
        $this->assertSame('0', $result['diff']);
        $this->assertSame('0', $result['channel_total']);
        $this->assertSame('0', $result['system_total']);
    }

    public function testRunRejectsInvalidDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PaymentReconcile())->run('2026-13-01');
    }

    public function testRunUpsertsUnverifiedRowForChannelWithoutReport(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('payment_channels', function ($t) {
            $t->increments('id');
            $t->string('code');
            $t->string('status');
        });
        $schema->create('payment_transactions', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('channel_id');
            $t->string('currency');
            $t->float('amount');
            $t->string('status');
            $t->dateTime('created_at');
        });
        $schema->create('payment_reconcile', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('channel_id');
            $t->string('date');
            $t->string('channel_total');
            $t->string('system_total');
            $t->string('diff');
            $t->string('status');
            // 镜像生产 uniq_reconcile_channel_date：upsert 的 ON CONFLICT 依赖唯一索引
            $t->unique(['channel_id', 'date']);
        });

        Capsule::table('payment_channels')->insert([
            ['id' => 1, 'code' => 'alipay', 'status' => 'active'],
        ]);
        Capsule::table('payment_transactions')->insert([
            ['id' => 1, 'channel_id' => 1, 'currency' => 'USD', 'amount' => 12.5,  'status' => 'success', 'created_at' => '2026-08-01 09:00:00'],
            ['id' => 2, 'channel_id' => 1, 'currency' => 'USD', 'amount' => 7.5,   'status' => 'success', 'created_at' => '2026-08-01 10:00:00'],
            ['id' => 3, 'channel_id' => 1, 'currency' => 'CNY', 'amount' => 99.99, 'status' => 'failed',  'created_at' => '2026-08-01 11:00:00'],
        ]);

        (new PaymentReconcile())->run('2026-08-01');

        $row = Capsule::table('payment_reconcile')->where('channel_id', 1)->where('date', '2026-08-01')->first();
        $this->assertNotNull($row);
        $this->assertSame('unverified', $row->status, '无报表实现通道必须显式 unverified');
        // 本地汇总：仅 success 计入，failed 排除
        $this->assertSame('20.0000', (string) $row->system_total);
        $this->assertSame($row->system_total, $row->channel_total);
        $this->assertSame('0', $row->diff);
    }
}
