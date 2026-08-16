<?php

namespace Tests\Order;

use App\Order\Model\Coupon;
use App\Order\Service\OrderService;
use Erikwang2013\WebmanScout\ModelObserver;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class OrderServiceCouponTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // 裸 Eloquent 环境无事件调度器，Snowflake creating 钩子不生效；挂上 dispatcher。
        // 只能设置一次：每次新建 dispatcher 会让 boot 期注册的模型事件监听落空。
        if (!\Illuminate\Database\Eloquent\Model::getEventDispatcher()) {
            \Illuminate\Database\Eloquent\Model::setEventDispatcher(
                new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container)
            );
        }

        // Order 模型带 Searchable，测试环境无索引配置：禁用同步回调
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

        Capsule::table('products')->insert(['id' => 1, 'name' => 'VPS']);
        Capsule::table('product_skus')->insert(['id' => 1, 'product_id' => 1, 'specs' => '[]']);
        Capsule::table('carts')->insert(['id' => 1, 'user_id' => 100, 'sku_id' => 1, 'region_id' => 1, 'quantity' => 1, 'cycle' => 'monthly']);
        Capsule::table('product_regions')->insert([
            'id' => 1, 'sku_id' => 1, 'region_id' => 1, 'currency' => 'USD',
            'price' => '20.0000', 'stock' => 10,
        ]);
        Capsule::table('coupons')->insert([
            'id' => 1, 'code' => 'SAVE10', 'type' => 'percentage', 'value' => '10.00',
            'min_amount' => '0.0000', 'max_discount' => null, 'max_uses' => 5,
            'used_count' => 0, 'starts_at' => null, 'expires_at' => null,
            'status' => 'active', 'created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-01 10:00:00',
        ]);
    }

    public function testCouponAppliesDiscountAndWritesUserCoupons(): void
    {
        $order = (new OrderService())->createFromCart(100, [1], 'USD', 'SAVE10');

        $this->assertSame(0, bccomp((string) $order->discount, '2.0000', 4));
        $this->assertSame(0, bccomp((string) $order->total, '18.0000', 4));

        $coupon = Coupon::find(1);
        $this->assertSame(1, (int) $coupon->used_count);

        $row = Capsule::table('user_coupons')->where('user_id', 100)->where('coupon_id', 1)->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $order->id, (int) $row->order_id);
        $this->assertNotNull($row->used_at);
    }

    public function testSecondRedemptionIncrementsUsedCount(): void
    {
        (new OrderService())->createFromCart(100, [1], 'USD', 'SAVE10');
        Capsule::table('carts')->insert(['id' => 2, 'user_id' => 100, 'sku_id' => 1, 'region_id' => 1, 'quantity' => 1, 'cycle' => 'monthly']);

        (new OrderService())->createFromCart(100, [2], 'USD', 'SAVE10');

        $this->assertSame(2, (int) Coupon::find(1)->used_count);
        $this->assertSame(2, Capsule::table('user_coupons')->count());
    }

    public function testExhaustedCouponRejectsOrder(): void
    {
        Capsule::table('coupons')->where('id', 1)->update(['used_count' => 5]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Coupon is invalid or expired');
        (new OrderService())->createFromCart(100, [1], 'USD', 'SAVE10');
    }

    public function testWithoutCouponCodeNoUserCouponRow(): void
    {
        $order = (new OrderService())->createFromCart(100, [1], 'USD');

        $this->assertSame(0, bccomp((string) $order->discount, '0.0000', 4));
        $this->assertSame(0, Capsule::table('user_coupons')->count());
        $this->assertSame(0, (int) Coupon::find(1)->used_count);
    }
}
