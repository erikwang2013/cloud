<?php

namespace Tests\Order;

use App\Order\Service\OrderService;
use Common\Money\Money;
use Erikwang2013\WebmanScout\ModelObserver;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * D5 恒等式：total - subtotal - tax + discount == 0 对任意订单恒成立（零对账漂移）。
 * SQLite 不强制 DECIMAL(12,4) 精度，可写入 5 位小数单价以覆盖舍入边界。
 */
final class OrderIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        if (!\Illuminate\Database\Eloquent\Model::getEventDispatcher()) {
            \Illuminate\Database\Eloquent\Model::setEventDispatcher(
                new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container)
            );
        }
        ModelObserver::disableSyncingFor(\App\Order\Model\Order::class);

        $schema = $capsule->schema();
        $schema->create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('status')->default('active');
        });
        $schema->create('product_skus', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->text('specs')->nullable();
            $t->string('status')->default('active');
        });
        $schema->create('carts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('sku_id');
            $t->unsignedBigInteger('region_id');
            $t->integer('quantity');
            $t->string('cycle')->default('monthly');
        });
        $schema->create('product_regions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sku_id');
            $t->unsignedBigInteger('region_id');
            $t->string('currency', 3);
            $t->decimal('price', 14, 4);
            $t->decimal('original_price', 14, 4)->nullable();
            $t->integer('stock')->default(0);
            $t->timestamps();
        });
        $schema->create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('order_no');
            $t->unsignedBigInteger('user_id');
            $t->string('type');
            $t->string('status');
            $t->string('currency', 3);
            $t->decimal('subtotal', 14, 4)->nullable();
            $t->decimal('discount', 14, 4)->nullable();
            $t->decimal('tax', 14, 4)->nullable();
            $t->decimal('total', 14, 4)->nullable();
            $t->string('exchange_rate')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        $schema->create('order_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('sku_id');
            $t->unsignedBigInteger('region_id');
            $t->unsignedBigInteger('product_id');
            $t->integer('quantity');
            $t->string('cycle');
            $t->decimal('unit_price', 14, 4);
            $t->decimal('total_price', 14, 4);
            $t->text('resource_snapshot')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $schema->create('order_timeline', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->string('status');
            $t->string('operator');
            $t->string('remark')->nullable();
            $t->timestamps();
        });
        $schema->create('coupons', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('code', 50)->unique();
            $t->string('type', 20)->default('percentage');
            $t->decimal('value', 10, 2);
            $t->decimal('min_amount', 16, 4)->default(0);
            $t->decimal('max_discount', 16, 4)->nullable();
            $t->integer('max_uses')->default(0);
            $t->integer('used_count')->default(0);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('status', 20)->default('active');
            $t->timestamps();
        });
        $schema->create('user_coupons', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('coupon_id');
            $t->unsignedBigInteger('order_id')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
    }

    private function insertBasics(): void
    {
        Capsule::table('products')->insert(['id' => 1, 'name' => 'VPS']);
        Capsule::table('product_skus')->insert(['id' => 1, 'product_id' => 1, 'specs' => '[]']);
    }

    private function assertIdentity($order): void
    {
        $identity = bcadd(
            bcsub(bcsub((string) $order->total, (string) $order->subtotal, 4), (string) $order->tax, 4),
            (string) $order->discount,
            4
        );
        $this->assertSame(0, bccomp($identity, '0', 4));
    }

    public function testMultiLineIdentityHoldsExactlyWithFiveDecimalPrices(): void
    {
        $this->insertBasics();
        Capsule::table('product_regions')->insert([
            ['id' => 1, 'sku_id' => 1, 'region_id' => 1, 'currency' => 'USD', 'price' => '0.12345', 'stock' => 100],
            ['id' => 2, 'sku_id' => 1, 'region_id' => 2, 'currency' => 'USD', 'price' => '19.99005', 'stock' => 100],
        ]);
        Capsule::table('carts')->insert([
            ['id' => 1, 'user_id' => 100, 'sku_id' => 1, 'region_id' => 1, 'quantity' => 3, 'cycle' => 'monthly'],
            ['id' => 2, 'user_id' => 100, 'sku_id' => 1, 'region_id' => 2, 'quantity' => 2, 'cycle' => 'monthly'],
        ]);
        Capsule::table('coupons')->insert([
            'id' => 1, 'code' => 'SAVE10', 'type' => 'percentage', 'value' => '10.00',
            'min_amount' => '0.0000', 'max_discount' => null, 'max_uses' => 5,
            'used_count' => 0, 'status' => 'active',
        ]);

        $order = (new OrderService())->createFromCart(100, [1, 2], 'USD', 'SAVE10');

        $this->assertIdentity($order);

        // subtotal = 每行 bcround(price × qty, 4) 的精确和（5 位小数单价先舍入到 4 位）
        $expectedSubtotal = bcadd(
            Money::bcround(bcmul('0.12345', '3', 8), 4),
            Money::bcround(bcmul('19.99005', '2', 8), 4),
            4
        );
        $this->assertSame(0, bccomp((string) $order->subtotal, $expectedSubtotal, 4));

        // discount = bcround(subtotal × 10%, 4)，total = subtotal - discount
        $expectedDiscount = Money::bcround(bcmul($expectedSubtotal, '0.1', 8), 4);
        $this->assertSame(0, bccomp((string) $order->discount, $expectedDiscount, 4));
        $this->assertSame(0, bccomp((string) $order->total, bcsub($expectedSubtotal, $expectedDiscount, 4), 4));
    }

    public function testMultiCurrencyOrderSnapshotsRateAndKeepsIdentity(): void
    {
        $this->insertBasics();
        Capsule::table('product_regions')->insert([
            'id' => 1, 'sku_id' => 1, 'region_id' => 1, 'currency' => 'EUR', 'price' => '12.3400', 'stock' => 10,
        ]);
        Capsule::table('carts')->insert([
            'id' => 1, 'user_id' => 100, 'sku_id' => 1, 'region_id' => 1, 'quantity' => 2, 'cycle' => 'monthly',
        ]);

        $order = (new OrderService())->createFromCart(100, [1], 'EUR');

        // 快照率写在订单行上（测试环境无 Redis 外观容器，走 1.000000 fallback）；
        // 换算点 = 结算写库时，恒等式与币种/汇率无关
        $this->assertSame('EUR', $order->currency);
        $this->assertSame('1.000000', (string) $order->exchange_rate);
        $this->assertIdentity($order);
        $this->assertSame(0, bccomp((string) $order->total, '24.6800', 4));
    }

    public function testNoCouponOrderIdentity(): void
    {
        $this->insertBasics();
        Capsule::table('product_regions')->insert([
            'id' => 1, 'sku_id' => 1, 'region_id' => 1, 'currency' => 'USD', 'price' => '9.9900', 'stock' => 10,
        ]);
        Capsule::table('carts')->insert([
            'id' => 1, 'user_id' => 100, 'sku_id' => 1, 'region_id' => 1, 'quantity' => 1, 'cycle' => 'monthly',
        ]);

        $order = (new OrderService())->createFromCart(100, [1], 'USD');

        $this->assertIdentity($order);
        $this->assertSame(0, bccomp((string) $order->discount, '0.0000', 4));
        $this->assertSame(0, bccomp((string) $order->total, '9.9900', 4));
    }
}
