<?php

namespace Tests\cron;

use App\cron\SupplierSettlement;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class SupplierSettlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('supplier_products', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('supplier_id');
            $t->unsignedBigInteger('product_id');
            $t->dateTime('approved_at')->nullable();
            $t->decimal('commission_rate', 5, 4)->default('0.1000');
        });
        $schema->create('products', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('supplier_id');
        });
        $schema->create('product_skus', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('product_id');
        });
        $schema->create('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('order_no');
            $t->unsignedBigInteger('user_id');
            $t->string('status');
            $t->string('currency', 3)->default('USD');
            $t->decimal('exchange_rate', 12, 6)->default('1.000000');
            $t->decimal('total', 14, 4);
        });
        $schema->create('order_items', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('sku_id');
            $t->unsignedBigInteger('product_id');
            $t->decimal('total_price', 14, 4);
        });
        $schema->create('order_timeline', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->string('status');
            $t->timestamp('created_at')->nullable();
        });
    }

    /**
     * #7 修复回归：多供应商订单（A 60 + B 40）按各自 order_items 金额结算，
     * 佣金用 supplier_products.commission_rate（0.2000 = 20%），不是整单 total。
     */
    public function testMultiSupplierOrderSettlesOnlyOwnItems(): void
    {
        // 供应商 1 -> 产品 10（10%），供应商 2 -> 产品 20（20%）
        Capsule::table('products')->insert([
            ['id' => 10, 'supplier_id' => 1],
            ['id' => 20, 'supplier_id' => 2],
        ]);
        Capsule::table('product_skus')->insert([
            ['id' => 100, 'product_id' => 10],
            ['id' => 200, 'product_id' => 20],
        ]);
        Capsule::table('supplier_products')->insert([
            ['id' => 1, 'supplier_id' => 1, 'product_id' => 10, 'approved_at' => date('Y-m-d H:i:s'), 'commission_rate' => '0.1000'],
            ['id' => 2, 'supplier_id' => 2, 'product_id' => 20, 'approved_at' => date('Y-m-d H:i:s'), 'commission_rate' => '0.2000'],
        ]);
        Capsule::table('orders')->insert([
            'id' => 1, 'order_no' => 'ORD-1', 'user_id' => 1, 'status' => 'completed', 'total' => '100.0000',
        ]);
        Capsule::table('order_items')->insert([
            ['id' => 1, 'order_id' => 1, 'sku_id' => 100, 'product_id' => 10, 'total_price' => '60.0000'],
            ['id' => 2, 'order_id' => 1, 'sku_id' => 200, 'product_id' => 20, 'total_price' => '40.0000'],
        ]);
        Capsule::table('order_timeline')->insert([
            'id' => 1, 'order_id' => 1, 'status' => 'completed', 'created_at' => '2026-08-20 12:00:00',
        ]);

        [$totalA, $commissionA, $payableA] = SupplierSettlement::salesAndCommission(1, '2026-08-17', '2026-08-23');
        $this->assertSame('60.0000', $totalA);
        $this->assertSame('6.0000', $commissionA);
        $this->assertSame('54.0000', $payableA);

        [$totalB, $commissionB, $payableB] = SupplierSettlement::salesAndCommission(2, '2026-08-17', '2026-08-23');
        $this->assertSame('40.0000', $totalB);
        $this->assertSame('8.0000', $commissionB);
        $this->assertSame('32.0000', $payableB);
    }

    /**
     * #7 边界：窗口为 [weekStart 00:00:00, weekEnd 23:59:59]，两端含、窗口外不含。
     */
    public function testWindowBoundariesAreInclusive(): void
    {
        $this->seedCatalog(1, 10, 100, '0.1000');
        $this->seedCompletedOrder(1, 10, '30.0000', '2026-08-10 00:00:00');
        $this->seedCompletedOrder(2, 10, '30.0000', '2026-08-16 23:59:59');
        $this->seedCompletedOrder(3, 10, '30.0000', '2026-08-09 23:59:59');
        $this->seedCompletedOrder(4, 10, '30.0000', '2026-08-17 00:00:00');

        $this->assertSame(
            ['60.0000', '6.0000', '54.0000'],
            SupplierSettlement::salesAndCommission(1, '2026-08-10', '2026-08-16')
        );
    }

    /**
     * #7 边界：有 completed timeline 但订单本身未 completed，不计入结算。
     */
    public function testOrderNotCompletedExcluded(): void
    {
        $this->seedCatalog(1, 10, 100, '0.1000');
        $this->seedCompletedOrder(1, 10, '30.0000', '2026-08-12 10:00:00', 'paid');

        $this->assertSame(
            ['0.0000', '0.0000', '0.0000'],
            SupplierSettlement::salesAndCommission(1, '2026-08-10', '2026-08-16')
        );
    }

    /**
     * #7 边界：supplier_products 无该商品费率时回落 10%。
     */
    public function testMissingCommissionRateDefaultsToTenPercent(): void
    {
        $this->seedCatalog(1, 10, 100, null);
        $this->seedCompletedOrder(1, 10, '50.0000', '2026-08-12 10:00:00');

        $this->assertSame(
            ['50.0000', '5.0000', '45.0000'],
            SupplierSettlement::salesAndCommission(1, '2026-08-10', '2026-08-16')
        );
    }

    /**
     * 费率防护：负值/超过 100% 的脏数据回落默认 10%，不得放大应付金额。
     */
    public function testInvalidCommissionRatesFallBackToTenPercent(): void
    {
        // 供应商 1 费率 -1，供应商 2 费率 1.5（150%）
        $this->seedCatalog(1, 10, 100, '-1.0000');
        $this->seedCatalog(2, 20, 200, '1.5000');
        $this->seedCompletedOrder(1, 10, '50.0000', '2026-08-12 10:00:00');
        $this->seedCompletedOrder(2, 20, '50.0000', '2026-08-12 10:00:00');

        $this->assertSame(
            ['50.0000', '5.0000', '45.0000'],
            SupplierSettlement::salesAndCommission(1, '2026-08-10', '2026-08-16')
        );
        $this->assertSame(
            ['50.0000', '5.0000', '45.0000'],
            SupplierSettlement::salesAndCommission(2, '2026-08-10', '2026-08-16')
        );
    }

    /**
     * 结算统一折算 USD：非 USD 用订单快照 exchange_rate（外币/USD）除以汇率。
     * 720 CNY @ 7.2 = 100 USD；10% 佣金 = 10 USD。
     */
    public function testNonUsdOrderConvertedBySnapshotRate(): void
    {
        $this->seedCatalog(1, 10, 100, '0.1000');
        Capsule::table('orders')->insert([
            'id' => 1, 'order_no' => 'ORD-CNY', 'user_id' => 1, 'status' => 'completed',
            'currency' => 'CNY', 'exchange_rate' => '7.200000', 'total' => '720.0000',
        ]);
        Capsule::table('order_items')->insert([
            ['id' => 1, 'order_id' => 1, 'sku_id' => 100, 'product_id' => 10, 'total_price' => '720.0000'],
        ]);
        Capsule::table('order_timeline')->insert([
            'id' => 1, 'order_id' => 1, 'status' => 'completed', 'created_at' => '2026-08-12 10:00:00',
        ]);

        $this->assertSame(
            ['100.0000', '10.0000', '90.0000'],
            SupplierSettlement::salesAndCommission(1, '2026-08-10', '2026-08-16')
        );
    }

    /**
     * 未审批的 supplier_products 费率不得生效（防止供应商自设 0/负费率）。
     */
    public function testUnapprovedCommissionRateIsIgnored(): void
    {
        $this->seedCatalog(1, 10, 100, '0.0000');
        Capsule::table('supplier_products')->where('product_id', 10)->update(['approved_at' => null]);
        $this->seedCompletedOrder(1, 10, '50.0000', '2026-08-12 10:00:00');

        $this->assertSame(
            ['50.0000', '5.0000', '45.0000'],
            SupplierSettlement::salesAndCommission(1, '2026-08-10', '2026-08-16')
        );
    }

    /**
     * 0% 是合法费率（平台补贴供应商）：不得被防护逻辑误判为脏数据。
     */
    public function testZeroCommissionRateIsAllowed(): void
    {
        $this->seedCatalog(1, 10, 100, '0.0000');
        $this->seedCompletedOrder(1, 10, '50.0000', '2026-08-12 10:00:00');

        $this->assertSame(
            ['50.0000', '0.0000', '50.0000'],
            SupplierSettlement::salesAndCommission(1, '2026-08-10', '2026-08-16')
        );
    }

    /**
     * #7 边界：佣金逐行 HALF_UP 到 4 位后累计（不是先合计再乘）。
     * 两行各 0.0001 × 50% = 0.00005 → 每行 0.0001，合计 0.0002；
     * 若先合计再乘则为 0.0001，断言能区分两种实现。
     */
    public function testCommissionRoundsPerItem(): void
    {
        $this->seedCatalog(1, 10, 100, '0.5000');
        Capsule::table('orders')->insert([
            'id' => 1, 'order_no' => 'ORD-1', 'user_id' => 1, 'status' => 'completed', 'total' => '0.0002',
        ]);
        Capsule::table('order_items')->insert([
            ['id' => 1, 'order_id' => 1, 'sku_id' => 100, 'product_id' => 10, 'total_price' => '0.0001'],
            ['id' => 2, 'order_id' => 1, 'sku_id' => 100, 'product_id' => 10, 'total_price' => '0.0001'],
        ]);
        Capsule::table('order_timeline')->insert([
            'id' => 1, 'order_id' => 1, 'status' => 'completed', 'created_at' => '2026-08-12 10:00:00',
        ]);

        $this->assertSame(
            ['0.0002', '0.0002', '0.0000'],
            SupplierSettlement::salesAndCommission(1, '2026-08-10', '2026-08-16')
        );
    }

    private function seedCatalog(int $supplierId, int $productId, int $skuId, ?string $rate): void
    {
        Capsule::table('products')->insert(['id' => $productId, 'supplier_id' => $supplierId]);
        Capsule::table('product_skus')->insert(['id' => $skuId, 'product_id' => $productId]);
        if ($rate !== null) {
            Capsule::table('supplier_products')->insert([
                'supplier_id' => $supplierId, 'product_id' => $productId,
                'approved_at' => date('Y-m-d H:i:s'),
                'commission_rate' => $rate,
            ]);
        }
    }

    private function seedCompletedOrder(
        int $orderId,
        int $productId,
        string $amount,
        string $completedAt,
        string $status = 'completed'
    ): void {
        Capsule::table('orders')->insert([
            'id' => $orderId, 'order_no' => 'ORD-' . $orderId, 'user_id' => 1,
            'status' => $status, 'total' => $amount,
        ]);
        Capsule::table('order_items')->insert([
            'id' => $orderId, 'order_id' => $orderId,
            'sku_id' => $productId * 10, 'product_id' => $productId, 'total_price' => $amount,
        ]);
        Capsule::table('order_timeline')->insert([
            'id' => $orderId, 'order_id' => $orderId, 'status' => 'completed', 'created_at' => $completedAt,
        ]);
    }

    /**
     * D4 修复回归：结算 = total - commission，全程字符串 bcmath，
     * 写 DECIMAL(14,4) 前 bcround 到 4 位。
     * 期望值用独立 bcmath 计算交叉验证后固化。
     */
    public function testSettleUsesBcmath(): void
    {
        $this->assertSame(['1234.5679', '154.9383', '1079.6296'], SupplierSettlement::settle('1234.56789', '12.55'));
    }

    public function testSettleRoundsHalfUpAt4Scale(): void
    {
        $this->assertSame(['100.0000', '10.0000', '90.0000'], SupplierSettlement::settle('99.99995', '10'));
    }

    public function testSettleLargeTotal(): void
    {
        $this->assertSame(['1000000.1235', '35000.0043', '965000.1192'], SupplierSettlement::settle('1000000.12345', '3.5'));
    }

    public function testSettlePayableIsExactDifference(): void
    {
        [$total, $commission, $payable] = SupplierSettlement::settle('88888.88888', '7.25');
        $this->assertSame(0, bccomp(bcsub($total, $commission, 4), $payable, 4));
        $this->assertSame(4, strlen(substr(strrchr($payable, '.'), 1)));
    }

    public function testSettleZeroCommission(): void
    {
        $this->assertSame(['500.1234', '0.0000', '500.1234'], SupplierSettlement::settle('500.1234', '0'));
    }
}
