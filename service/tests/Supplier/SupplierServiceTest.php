<?php

namespace Tests\Supplier;

use App\Supplier\Service\SupplierService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Redis as RedisFacade;
use PHPUnit\Framework\TestCase;

/**
 * #7/#9 修复回归：requestWithdraw 正金额校验 + 可用额实时计算（completed 累计 - pending/processing 扣减），
 * generateSettlement 逐行 bcmath 累计 + 同周期幂等。
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

        // WebhookDispatcher 仅在 webhook_urls 非空时投递，swap 空实现避免真实 Redis
        RedisFacade::swap(new class {
            public function smembers(string $key): array { return []; }
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
        $schema->create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->unsignedBigInteger('supplier_id');
            $t->string('status')->default('active');
        });
        $schema->create('product_skus', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('status')->default('active');
        });
        $schema->create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('order_no');
            $t->unsignedBigInteger('user_id');
            $t->string('status');
            $t->string('currency', 3)->default('USD');
            $t->decimal('total', 14, 4)->nullable();
            $t->timestamp('paid_at')->nullable();
        });
        $schema->create('order_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('sku_id');
            $t->decimal('total_price', 14, 4);
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

    public function testGenerateSettlementIdempotent(): void
    {
        Capsule::table('supplier_settlements')->insert([
            'supplier_id'  => 1, 'period_start' => '2026-08-17', 'period_end' => '2026-08-23',
            'total_sales'  => '9.9900', 'commission' => '0.9990', 'payable' => '8.9910', 'status' => 'pending',
        ]);

        $existing = Capsule::table('supplier_settlements')->first();
        $result = (new SupplierService())->generateSettlement(1, '2026-08-17', '2026-08-23');

        $this->assertSame((int) $existing->id, (int) $result->id);
        $this->assertSame(1, Capsule::table('supplier_settlements')->count());
    }

    public function testGenerateSettlementBcmathAccumulation(): void
    {
        Capsule::table('products')->insert(['id' => 1, 'name' => 'P1', 'supplier_id' => 1]);
        Capsule::table('product_skus')->insert(['id' => 1, 'product_id' => 1]);
        Capsule::table('orders')->insert([
            ['id' => 1, 'order_no' => 'A', 'user_id' => 1, 'status' => 'completed', 'paid_at' => '2026-08-20 10:00:00'],
            ['id' => 2, 'order_no' => 'B', 'user_id' => 1, 'status' => 'completed', 'paid_at' => '2026-08-21 10:00:00'],
        ]);
        Capsule::table('order_items')->insert([
            ['id' => 1, 'order_id' => 1, 'sku_id' => 1, 'total_price' => '10.12345'],
            ['id' => 2, 'order_id' => 2, 'sku_id' => 1, 'total_price' => '20.67890'],
        ]);

        $s = (new SupplierService())->generateSettlement(1, '2026-08-17', '2026-08-23');

        // 逐行 bcadd/bcmul scale=4 截断累计（非 HALF_UP）：10.1234 + 20.6789 = 30.8023；
        // 佣金逐行截断：1.0123 + 2.0678 = 3.0801
        $this->assertSame('30.8023', (string) $s->total_sales);
        $this->assertSame('3.0801', (string) $s->commission);
        $this->assertSame(0, bccomp((string) $s->payable, bcsub('30.8023', '3.0801', 4), 4));
    }
}
