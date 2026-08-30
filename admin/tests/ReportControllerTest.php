<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests;

use app\controller\ReportController;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use support\exception\BusinessException;
use support\Response;
use tests\Support\RequestMock;

use function hashids_decode;

/**
 * 报表模块：订单日报 / 商品销量排行 / 支付渠道统计 / 用户增长 / Excel 导出。
 * 与 DictTest 同模式：内存 sqlite 直连模型，验证查询逻辑与参数校验。
 * 注意：sqlite 无 DECIMAL 列，金额用 INTEGER 播种，汇总断言精确整数；
 * MySQL 下 PDO 返回的 DECIMAL 字符串形态（如 "0" / "123.4500"）需在 MySQL 环境冒烟验证。
 */
final class ReportControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'mysql');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema('mysql');
        $schema->create('orders', function ($t) {
            $t->integer('id');
            $t->string('status');
            $t->string('currency', 8)->default('CNY');
            $t->integer('total')->default(0);
            // orders.exchange_rate = 币种→USD 汇率（USD=1.0），商品排行金额按此折算为 USD 基准
            $t->integer('exchange_rate')->default(1);
            $t->timestamp('paid_at')->nullable();
        });
        $schema->create('order_items', function ($t) {
            $t->integer('id');
            $t->integer('order_id');
            $t->integer('product_id');
            $t->integer('quantity');
            $t->integer('total_price')->default(0);
        });
        $schema->create('products', function ($t) {
            $t->integer('id');
            $t->string('name');
        });
        $schema->create('payment_channels', function ($t) {
            $t->integer('id');
            $t->string('name');
        });
        $schema->create('payment_transactions', function ($t) {
            $t->integer('id');
            $t->integer('channel_id');
            $t->string('status');
            $t->string('currency', 8)->default('CNY');
            $t->integer('amount')->default(0);
            $t->timestamp('created_at')->nullable();
        });
        $schema->create('users', function ($t) {
            $t->integer('id');
            $t->string('email');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
    }

    private function db(): Connection
    {
        return Capsule::connection('mysql');
    }

    private function day(int $daysAgo): string
    {
        return date('Y-m-d', time() - $daysAgo * 86400);
    }

    private function ts(int $daysAgo, string $time = '12:00:00'): string
    {
        return $this->day($daysAgo) . ' ' . $time;
    }

    private function controller(): ReportController
    {
        return new ReportController();
    }

    /** @return array{code:int, data:array, msg:string} */
    private function body(Response $response): array
    {
        $decoded = json_decode((string) $response->rawBody(), true);
        $this->assertSame(0, $decoded['code'] ?? -1, 'expected success response');
        return $decoded['data'];
    }

    /** @return string[] */
    private function exportFiles(): array
    {
        return glob(base_path('runtime/exports/export_*.xlsx')) ?: [];
    }

    public function testIndexRendersReportView(): void
    {
        $response = $this->controller()->index();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<', (string) $response->rawBody());
    }

    public function testOrderRejectsInvalidDates(): void
    {
        $ctrl = $this->controller();
        foreach (['start_date' => '开始日期格式应为 YYYY-MM-DD', 'end_date' => '结束日期格式应为 YYYY-MM-DD'] as $param => $msg) {
            try {
                $ctrl->order(new RequestMock([$param => '2026/08/01'], []));
                $this->fail("order() must reject $param=2026/08/01");
            } catch (BusinessException $e) {
                $this->assertSame($msg, $e->getMessage());
            }
        }

        try {
            $ctrl->order(new RequestMock(['start_date' => $this->day(0), 'end_date' => $this->day(1)], []));
            $this->fail('order() must reject start_date > end_date');
        } catch (BusinessException $e) {
            $this->assertSame('开始日期不能晚于结束日期', $e->getMessage());
        }
    }

    public function testOrderDefaultsToLast30Days(): void
    {
        $data = $this->body($this->controller()->order(new RequestMock([], [])));
        $this->assertSame(
            ['start' => $this->day(29) . ' 00:00:00', 'end' => $this->day(0) . ' 23:59:59'],
            $data['range']
        );
        $this->assertSame(0, $data['totals']['orders']);
        $this->assertSame([], $data['totals']['revenue_by_currency']);
        $this->assertSame([], $data['daily']);
    }

    public function testOrderDailyAggregatesPaidNonRefunded(): void
    {
        $this->db()->table('orders')->insert([
            ['id' => 1, 'status' => 'paid', 'currency' => 'CNY', 'total' => 100, 'paid_at' => $this->ts(0, '10:00:00')],
            ['id' => 2, 'status' => 'paid', 'currency' => 'CNY', 'total' => 50, 'paid_at' => $this->ts(0, '14:00:00')],
            ['id' => 3, 'status' => 'refunded', 'currency' => 'CNY', 'total' => 999, 'paid_at' => $this->ts(0, '15:00:00')],
            ['id' => 4, 'status' => 'paid', 'currency' => 'USD', 'total' => 200, 'paid_at' => $this->ts(1, '10:00:00')],
        ]);

        // 单日区间：refunded 排除，昨日订单不在区间
        $data = $this->body($this->controller()->order(new RequestMock([
            'start_date' => $this->day(0),
            'end_date' => $this->day(0),
        ], [])));
        $this->assertSame(2, $data['totals']['orders']);
        $this->assertSame(['CNY' => '150.0000'], $data['totals']['revenue_by_currency'], 'bcadd 必须保留 4 位小数');
        $this->assertCount(1, $data['daily']);
        $this->assertSame(['date' => $this->day(0), 'currency' => 'CNY', 'revenue' => 150, 'orders' => 2], $data['daily'][0]);

        // 跨日区间：CNY + USD 多币种不可跨币种相加，按币种分组汇总
        $data = $this->body($this->controller()->order(new RequestMock([
            'start_date' => $this->day(1),
            'end_date' => $this->day(0),
        ], [])));
        $this->assertSame(3, $data['totals']['orders']);
        $this->assertSame('150.0000', $data['totals']['revenue_by_currency']['CNY']);
        $this->assertSame('200.0000', $data['totals']['revenue_by_currency']['USD']);
        $this->assertCount(2, $data['daily']);
    }

    public function testProductTopAggregatesAndClampsLimit(): void
    {
        $this->db()->table('orders')->insert([
            ['id' => 1, 'status' => 'paid', 'currency' => 'CNY', 'total' => 13260, 'exchange_rate' => 1, 'paid_at' => $this->ts(0, '10:00:00')],
        ]);
        $products = [];
        $items = [];
        for ($i = 1; $i <= 51; $i++) {
            $qty = 52 - $i; // 商品 1 销量最高 51，商品 51 最低 1
            $products[] = ['id' => $i, 'name' => "P$i"];
            $items[] = ['id' => $i, 'order_id' => 1, 'product_id' => $i, 'quantity' => $qty, 'total_price' => $qty * 10];
        }
        $this->db()->table('products')->insert($products);
        $this->db()->table('order_items')->insert($items);

        $base = ['start_date' => $this->day(0), 'end_date' => $this->day(0)];

        // limit=0 钳制到 1
        $data = $this->body($this->controller()->product_top(new RequestMock($base + ['limit' => '0'], [])));
        $this->assertCount(1, $data['items']);
        $this->assertSame(1, hashids_decode((string) $data['items'][0]['product_id']), 'product_id 必须经 hashids 编码');
        $this->assertSame('P1', $data['items'][0]['name']);
        $this->assertSame(51, $data['items'][0]['qty']);
        $this->assertSame(510, $data['items'][0]['amount']);

        // limit=51 钳制到 50
        $data = $this->body($this->controller()->product_top(new RequestMock($base + ['limit' => '51'], [])));
        $this->assertCount(50, $data['items']);

        // 默认 limit=10
        $data = $this->body($this->controller()->product_top(new RequestMock($base, [])));
        $this->assertCount(10, $data['items']);
        $this->assertSame(1, hashids_decode((string) $data['items'][0]['product_id']), '销量最高者排第一');
    }

    public function testProductTopConvertsAmountToUsdBase(): void
    {
        // 多币种回归：商品金额必须按 exchange_rate 折算为 USD 基准后再汇总，禁止跨币种直接相加
        $this->db()->table('orders')->insert([
            ['id' => 1, 'status' => 'paid', 'currency' => 'CNY', 'total' => 200, 'exchange_rate' => 1, 'paid_at' => $this->ts(0, '10:00:00')],
            ['id' => 2, 'status' => 'paid', 'currency' => 'USD', 'total' => 100, 'exchange_rate' => 2, 'paid_at' => $this->ts(0, '11:00:00')],
        ]);
        $this->db()->table('products')->insert([['id' => 1, 'name' => 'VPS']]);
        $this->db()->table('order_items')->insert([
            ['id' => 1, 'order_id' => 1, 'product_id' => 1, 'quantity' => 2, 'total_price' => 100], // CNY 100 × rate 1 = 100 USD
            ['id' => 2, 'order_id' => 2, 'product_id' => 1, 'quantity' => 1, 'total_price' => 50],  // USD 50 × rate 2 = 100 USD
        ]);

        $data = $this->body($this->controller()->product_top(new RequestMock([
            'start_date' => $this->day(0),
            'end_date' => $this->day(0),
        ], [])));

        $this->assertSame(1, hashids_decode((string) $data['items'][0]['product_id']));
        $this->assertSame(3, $data['items'][0]['qty']);
        $this->assertSame(200, $data['items'][0]['amount'], '折算后 100 + 100 = 200；直接相加则为 150');
    }

    public function testPaymentCountsOnlySuccess(): void
    {
        $this->db()->table('payment_channels')->insert([
            ['id' => 1, 'name' => '支付宝'],
            ['id' => 2, 'name' => '微信支付'],
        ]);
        $this->db()->table('payment_transactions')->insert([
            ['id' => 1, 'channel_id' => 1, 'status' => 'success', 'currency' => 'CNY', 'amount' => 100, 'created_at' => $this->ts(0, '10:00:00')],
            ['id' => 2, 'channel_id' => 1, 'status' => 'success', 'currency' => 'CNY', 'amount' => 50, 'created_at' => $this->ts(0, '11:00:00')],
            ['id' => 3, 'channel_id' => 1, 'status' => 'pending', 'currency' => 'CNY', 'amount' => 999, 'created_at' => $this->ts(0, '12:00:00')],
            ['id' => 4, 'channel_id' => 1, 'status' => 'failed', 'currency' => 'CNY', 'amount' => 999, 'created_at' => $this->ts(0, '13:00:00')],
            ['id' => 5, 'channel_id' => 2, 'status' => 'success', 'currency' => 'USD', 'amount' => 30, 'created_at' => $this->ts(0, '14:00:00')],
            ['id' => 6, 'channel_id' => 1, 'status' => 'success', 'currency' => 'CNY', 'amount' => 100, 'created_at' => $this->ts(1, '10:00:00')],
        ]);

        $data = $this->body($this->controller()->payment(new RequestMock([
            'start_date' => $this->day(0),
            'end_date' => $this->day(0),
        ], [])));

        $this->assertCount(2, $data['items'], 'pending/failed/昨日 必须排除');
        $this->assertSame('支付宝', $data['items'][0]['channel']);
        $this->assertSame('CNY', $data['items'][0]['currency']);
        $this->assertSame(2, $data['items'][0]['transactions']);
        $this->assertSame(150, $data['items'][0]['amount']);
        $this->assertArrayNotHasKey('name', $data['items'][0], '内部 name 字段必须改名为 channel');
        $this->assertSame('微信支付', $data['items'][1]['channel']);
        $this->assertSame('USD', $data['items'][1]['currency']);
        $this->assertSame(1, $data['items'][1]['transactions']);
    }

    public function testUserGrowthCountsActiveOnly(): void
    {
        $this->db()->table('users')->insert([
            ['id' => 1, 'email' => 'a@x.com', 'created_at' => $this->ts(0, '09:00:00'), 'deleted_at' => null],
            ['id' => 2, 'email' => 'b@x.com', 'created_at' => $this->ts(0, '10:00:00'), 'deleted_at' => $this->ts(0, '11:00:00')],
            ['id' => 3, 'email' => 'c@x.com', 'created_at' => $this->ts(1, '09:00:00'), 'deleted_at' => null],
        ]);

        $data = $this->body($this->controller()->user_growth(new RequestMock([
            'start_date' => $this->day(0),
            'end_date' => $this->day(0),
        ], [])));
        $this->assertSame([['date' => $this->day(0), 'count' => 1]], $data['items'], '软删用户不计');

        // 默认近 30 天：今天 + 昨天各一行
        $data = $this->body($this->controller()->user_growth(new RequestMock([], [])));
        $this->assertCount(2, $data['items']);
        $this->assertSame(1, $data['items'][0]['count']);
    }

    public function testExportRejectsUnknownType(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('未知导出类型');
        $this->controller()->export(new RequestMock([
            'type' => 'csv',
            'start_date' => $this->day(1),
            'end_date' => $this->day(0),
        ], []));
    }

    public static function exportTypeProvider(): array
    {
        return [
            'order'   => ['order', ['日期', '币种', '订单数', '营收']],
            'product' => ['product', ['商品名称', '销量', '金额']],
            'payment' => ['payment', ['支付渠道', '币种', '笔数', '金额']],
            'user'    => ['user', ['日期', '新增用户']],
        ];
    }

    #[DataProvider('exportTypeProvider')]
    public function testExportWritesXlsxWithExpectedHeaders(string $type, array $headers): void
    {
        $this->db()->table('orders')->insert([
            ['id' => 1, 'status' => 'paid', 'currency' => 'CNY', 'total' => 100, 'exchange_rate' => 1, 'paid_at' => $this->ts(0, '10:00:00')],
        ]);

        $before = $this->exportFiles();
        $response = $this->controller()->export(new RequestMock([
            'type' => $type,
            'start_date' => $this->day(1),
            'end_date' => $this->day(0),
        ], []));
        $new = array_values(array_diff($this->exportFiles(), $before));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $new, "export($type) 必须生成一个 xlsx 文件");
        $path = $new[0];
        try {
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
            foreach ($headers as $i => $label) {
                $this->assertSame(
                    $label,
                    $sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . '1')->getValue(),
                    "$type 表头 #$i"
                );
            }
            if ($type === 'order') {
                $this->assertSame($this->day(0), (string) $sheet->getCell('A2')->getValue());
                $this->assertSame('CNY', $sheet->getCell('B2')->getValue());
                $this->assertSame('1', (string) $sheet->getCell('C2')->getValue());
                $this->assertSame('100', (string) $sheet->getCell('D2')->getValue());
            }
        } finally {
            @unlink($path);
        }
    }
}
