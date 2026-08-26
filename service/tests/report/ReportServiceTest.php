<?php

namespace Tests\report;

use App\report\service\ReportService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

final class ReportServiceTest extends TestCase
{
    private const SEED = [
        // [order_no, status, currency, total, paid_at]
        'orders' => [
            ['O-1001', 'paid', 'USD', 100, '2026-08-01 10:00:00'],
            ['O-1002', 'paid', 'USD', 250, '2026-08-02 10:00:00'],
            ['O-1003', 'paid', 'CNY', 600, '2026-08-02 12:00:00'],
            ['O-1004', 'refunded', 'USD', 999, '2026-08-03 10:00:00'], // excluded
            ['O-1005', 'pending', 'USD', 50, '2026-08-03 10:00:00'],  // paid_at null excluded
            ['O-1006', 'paid', 'USD', 25, '2026-08-10 10:00:00'],     // outside range
        ],
        // [order_no, sku_id, region_id, product_id, total_price]
        'items' => [
            ['O-1001', 1, 1, 1, 100],
            ['O-1002', 1, 2, 1, 150],
            ['O-1002', 2, 1, 2, 100],
            ['O-1003', 2, 2, 2, 600],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('orders', function ($t) {
            $t->increments('id');
            $t->string('order_no');
            $t->string('status');
            $t->string('currency');
            $t->float('total');
            $t->dateTime('paid_at')->nullable();
        });
        $schema->create('order_items', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('order_id');
            $t->unsignedInteger('sku_id');
            $t->unsignedInteger('region_id');
            $t->unsignedInteger('product_id');
            $t->float('total_price');
        });
        $schema->create('product_skus', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('product_id');
        });
        $schema->create('products', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('category_id');
            $t->string('name');
        });
        $schema->create('product_categories', function ($t) {
            $t->increments('id');
            $t->string('name');
        });
        $schema->create('regions', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->string('continent');
        });
        $schema->create('supplier_settlements', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('supplier_id');
            $t->dateTime('period_start');
            $t->dateTime('period_end');
            $t->float('payable');
            $t->string('status');
        });

        foreach (self::SEED['orders'] as $row) {
            Capsule::table('orders')->insert([
                'order_no' => $row[0],
                'status'   => $row[1],
                'currency' => $row[2],
                'total'    => $row[3],
                'paid_at'  => $row[1] === 'pending' ? null : $row[4],
            ]);
        }
        foreach (self::SEED['items'] as $row) {
            Capsule::table('order_items')->insert([
                'order_id'    => Capsule::table('orders')->where('order_no', $row[0])->value('id'),
                'sku_id'      => $row[1],
                'region_id'   => $row[2],
                'product_id'  => $row[3],
                'total_price' => $row[4],
            ]);
        }
        Capsule::table('product_skus')->insert([
            ['id' => 1, 'product_id' => 1],
            ['id' => 2, 'product_id' => 2],
        ]);
        Capsule::table('products')->insert([
            ['id' => 1, 'category_id' => 1, 'name' => 'VPS'],
            ['id' => 2, 'category_id' => 2, 'name' => 'CDN'],
        ]);
        Capsule::table('product_categories')->insert([
            ['id' => 1, 'name' => 'Compute'],
            ['id' => 2, 'name' => 'Network'],
        ]);
        Capsule::table('regions')->insert([
            ['id' => 1, 'name' => 'US-East', 'continent' => 'NA'],
            ['id' => 2, 'name' => 'Frankfurt', 'continent' => 'EU'],
        ]);
        Capsule::table('supplier_settlements')->insert([
            ['id' => 1, 'supplier_id' => 5, 'period_start' => '2026-08-01 00:00:00', 'period_end' => '2026-08-31 23:59:59', 'payable' => 300, 'status' => 'paid'],
            ['id' => 2, 'supplier_id' => 5, 'period_start' => '2026-08-01 00:00:00', 'period_end' => '2026-08-31 23:59:59', 'payable' => 100, 'status' => 'pending'],
            ['id' => 3, 'supplier_id' => 9, 'period_start' => '2026-08-01 00:00:00', 'period_end' => '2026-08-31 23:59:59', 'payable' => 500, 'status' => 'paid'],
        ]);
    }

    public function testRevenueReportTotalsExcludeRefundedAndUnpaid(): void
    {
        $report = (new ReportService())->revenueReport('2026-08-01', '2026-08-09');

        // O-1001 (100) + O-1002 (250) + O-1003 (600) = 950（bcmath 汇总，4 位小数）
        $this->assertSame('950.0000', (string) $report['totalRevenue']);
        // 3 paid orders in range; refunded/pending/out-of-range excluded
        $this->assertSame(3, (int) $report['totalOrders']);
        // daily grouped by (date, currency): 08-01/USD, 08-02/USD, 08-02/CNY
        $this->assertCount(3, $report['daily']);
    }

    public function testRevenueReportGroupsByDateAndCurrency(): void
    {
        $report = (new ReportService())->revenueReport('2026-08-01', '2026-08-09');

        $byKey = [];
        foreach ($report['daily'] as $row) {
            $byKey[$row->date . '|' . $row->currency] = (string) $row->revenue;
        }

        $this->assertSame('100', $byKey['2026-08-01|USD']);
        $this->assertSame('250', $byKey['2026-08-02|USD']);
        $this->assertSame('600', $byKey['2026-08-02|CNY']);
        $this->assertCount(3, $byKey);
    }

    public function testRevenueReportByCategory(): void
    {
        $report = (new ReportService())->revenueReport('2026-08-01', '2026-08-09');

        $byCategory = $report['byCategory'];
        $this->assertCount(2, $byCategory);
        foreach ($byCategory as $cat) {
            if ($cat->name === 'Compute') {
                $this->assertSame('250', (string) $cat->revenue); // 100 + 150
            } else {
                $this->assertSame('Network', $cat->name);
                $this->assertSame('700', (string) $cat->revenue); // 100 + 600
            }
        }
    }

    public function testSupplierReportTotals(): void
    {
        $report = (new ReportService())->supplierReport(5, '2026-08-01', '2026-08-31');

        $this->assertSame('400.0000', (string) $report['totalPayable']);
        $this->assertSame('300.0000', (string) $report['totalPaid']);
        $this->assertCount(2, $report['settlements']);
    }

    public function testSalesByRegion(): void
    {
        $rows = (new ReportService())->salesByRegion('2026-08-01', '2026-08-09');

        // Frankfurt: 150 + 600 = 750, US-East: 100 + 100 = 200
        $this->assertCount(2, $rows);
        $this->assertSame('Frankfurt', $rows[0]->name);
        $this->assertSame('750', (string) $rows[0]->revenue);
        $this->assertSame('US-East', $rows[1]->name);
        $this->assertSame('200', (string) $rows[1]->revenue);
    }
}
