<?php

namespace Tests\supplier;

use App\supplier\service\SupplierService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Redis as RedisFacade;
use PHPUnit\Framework\TestCase;

/**
 * #8 修复回归：requestWithdraw 正金额校验 + 可用额实时计算
 * （completed 结算累计 - 所有未 rejected/cancelled 的提现扣减）。
 * SQLite 内存库（lockForUpdate 为 no-op，并发行锁语义不在本测试范围）。
 */
final class SupplierServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // WebhookDispatcher 仅在 webhook_urls 非空时投递，swap 空实现避免真实 Redis。
        // get() 一并提供：facade 假实现会泄漏到后续测试（如 OrderIdentityTest 的汇率读取）
        RedisFacade::swap(new class {
            public function smembers(string $key): array { return []; }
            public function get(string $key) { return null; }
        });

        $schema = $capsule->schema();
        $schema->create('suppliers', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('company_name')->nullable();
            $t->string('status')->default('pending');
            $t->string('settlement_method')->nullable();
        });
        $schema->create('supplier_settlements', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('supplier_id');
            $t->date('period_start');
            $t->date('period_end');
            $t->decimal('total_sales', 14, 4)->default('0');
            $t->decimal('commission', 14, 4)->default('0');
            $t->decimal('payable', 14, 4)->default('0');
            $t->string('status');
            $t->timestamps();
        });
        $schema->create('supplier_withdraws', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('supplier_id');
            $t->decimal('amount', 14, 4);
            $t->string('method');
            $t->text('account_info')->nullable();
            $t->string('status');
            $t->timestamps();
        });
        Capsule::table('suppliers')->insert([
            ['id' => 1, 'user_id' => 100, 'company_name' => 'Test Co', 'status' => 'active', 'settlement_method' => 'bank'],
        ]);
    }

    private function seedSettlement(int $supplierId, string $payable, string $status = 'completed'): void
    {
        Capsule::table('supplier_settlements')->insert([
            'supplier_id'  => $supplierId,
            'period_start' => '2026-08-17',
            'period_end'   => '2026-08-23',
            'total_sales'  => $payable,
            'commission'   => '0.0000',
            'payable'      => $payable,
            'status'       => $status,
        ]);
    }

    public function testRejectsNonPositiveAmount(): void
    {
        foreach (['0', '-0.01', '-100'] as $amount) {
            try {
                (new SupplierService())->requestWithdraw(1, $amount, ['method' => 'bank']);
                $this->fail("amount {$amount} should be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Withdraw amount must be positive', $e->getMessage());
            }
        }
    }

    public function testRejectsMalformedAmount(): void
    {
        foreach (['abc', '1.23456', '', '1e3'] as $amount) {
            try {
                (new SupplierService())->requestWithdraw(1, $amount, ['method' => 'bank']);
                $this->fail("amount {$amount} should be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Invalid withdraw amount', $e->getMessage());
            }
        }
    }

    public function testRejectsOverAvailable(): void
    {
        $this->seedSettlement(1, '100.0000');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient withdrawable balance');
        (new SupplierService())->requestWithdraw(1, '100.0001', ['method' => 'bank']);
    }

    public function testPendingWithdrawReducesAvailable(): void
    {
        $this->seedSettlement(1, '100.0000');
        Capsule::table('supplier_withdraws')->insert([
            'supplier_id' => 1, 'amount' => '60.0000', 'method' => 'bank', 'account_info' => '{}', 'status' => 'pending',
        ]);

        // 可用 = 100 - 60 = 40，取 40.01 拒绝
        $this->expectException(\InvalidArgumentException::class);
        (new SupplierService())->requestWithdraw(1, '40.0001', ['method' => 'bank']);
    }

    public function testCompletedWithdrawStillReducesAvailable(): void
    {
        $this->seedSettlement(1, '100.0000');
        Capsule::table('supplier_withdraws')->insert([
            'supplier_id' => 1, 'amount' => '100.0000', 'method' => 'bank', 'account_info' => '{}', 'status' => 'completed',
        ]);

        // 已完成的提现同样占用额度，不能同一笔钱再提
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient withdrawable balance');
        (new SupplierService())->requestWithdraw(1, '0.0001', ['method' => 'bank']);
    }

    public function testRejectedWithdrawReleasesAvailable(): void
    {
        $this->seedSettlement(1, '100.0000');
        Capsule::table('supplier_withdraws')->insert([
            'supplier_id' => 1, 'amount' => '100.0000', 'method' => 'bank', 'account_info' => '{}', 'status' => 'rejected',
        ]);

        (new SupplierService())->requestWithdraw(1, '100.0000', ['method' => 'bank']);
        $this->assertSame(2, Capsule::table('supplier_withdraws')->count());
    }

    public function testCancelledWithdrawReleasesAvailable(): void
    {
        $this->seedSettlement(1, '100.0000');
        Capsule::table('supplier_withdraws')->insert([
            'supplier_id' => 1, 'amount' => '100.0000', 'method' => 'bank', 'account_info' => '{}', 'status' => 'cancelled',
        ]);

        (new SupplierService())->requestWithdraw(1, '100.0000', ['method' => 'bank']);
        $this->assertSame(2, Capsule::table('supplier_withdraws')->count());
    }

    public function testPendingSettlementNotCountedAsAvailable(): void
    {
        $this->seedSettlement(1, '100.0000', 'pending');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient withdrawable balance');
        (new SupplierService())->requestWithdraw(1, '0.0001', ['method' => 'bank']);
    }

    public function testCreatesPendingWithdraw(): void
    {
        $this->seedSettlement(1, '100.0000');

        (new SupplierService())->requestWithdraw(1, '40.0000', ['method' => 'bank', 'account' => 'CN-001']);

        $row = Capsule::table('supplier_withdraws')->first();
        $this->assertSame('1', (string) $row->supplier_id);
        $this->assertSame(0, bccomp((string) $row->amount, '40.0000', 4));
        $this->assertSame('pending', $row->status);
        // 期望单层 JSON 对象（array cast 会自动 encode，显式 json_encode 会造成双编码）
        $this->assertSame(['method' => 'bank', 'account' => 'CN-001'], json_decode((string) $row->account_info, true));
    }
}
