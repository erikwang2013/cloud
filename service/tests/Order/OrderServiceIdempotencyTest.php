<?php

namespace Tests\Order;

use App\Order\Service\OrderService;
use Common\Snowflake\SnowflakeService;
use Erikwang2013\WebmanScout\ModelObserver;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 同一 cart 重复提交只生成一单：createFromCart 在事务内行锁重读 cart，
 * 提交即清空（状态转移），后到请求重读空集抛 'Cart is empty'。
 */
final class OrderServiceIdempotencyTest extends TestCase
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

        // 目录连跑时 Order 可能已被其他文件在无 dispatcher 下首次 boot（creating 钩子永久丢失），
        // 补挂雪花主键生成；若钩子已注册则幂等（key 已存在时 no-op）。
        \App\Order\Model\Order::creating(function ($model) {
            if (empty($model->getKey())) {
                $model->setAttribute($model->getKeyName(), SnowflakeService::nextId());
            }
        });

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

        Capsule::table('products')->insert(['id' => 1, 'name' => 'VPS']);
        Capsule::table('product_skus')->insert(['id' => 1, 'product_id' => 1, 'specs' => '[]']);
        Capsule::table('carts')->insert(['id' => 1, 'user_id' => 100, 'sku_id' => 1, 'region_id' => 1, 'quantity' => 1, 'cycle' => 'monthly']);
        Capsule::table('product_regions')->insert([
            'id' => 1, 'sku_id' => 1, 'region_id' => 1, 'currency' => 'USD',
            'price' => '20.0000', 'stock' => 10,
        ]);
    }

    public function testDoubleSubmitSameCartCreatesOnlyOneOrder(): void
    {
        $svc = new OrderService();

        $order = $svc->createFromCart(100, [1], 'USD');
        $this->assertSame(1, Capsule::table('orders')->count());
        $this->assertSame(0, Capsule::table('carts')->count(), '下单即清空 cart');

        // 第二次提交（并发窗口后重读）必须被拒绝，不再生成订单
        try {
            $svc->createFromCart(100, [1], 'USD');
            $this->fail('Expected InvalidArgumentException on duplicate submission');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Cart is empty', $e->getMessage());
        }

        $this->assertSame(1, Capsule::table('orders')->count());
        $this->assertSame(9, (int) Capsule::table('product_regions')->where('id', 1)->value('stock'), '库存只扣一次');
        $this->assertSame(1, Capsule::table('order_items')->count());
    }

    public function testFailedOrderKeepsCartForRetry(): void
    {
        Capsule::table('product_regions')->where('id', 1)->update(['stock' => 0]);

        try {
            (new OrderService())->createFromCart(100, [1], 'USD');
            $this->fail('Expected InvalidArgumentException on insufficient stock');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Insufficient stock for SKU 1', $e->getMessage());
        }

        // 事务回滚：cart 保留、无订单，可正常重试
        $this->assertSame(1, Capsule::table('carts')->count());
        $this->assertSame(0, Capsule::table('orders')->count());
    }

    public function testForeignCartRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cart is empty');
        (new OrderService())->createFromCart(999, [1], 'USD');
    }

    public function testEmptyCartIdsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cart is empty');
        (new OrderService())->createFromCart(100, [], 'USD');
    }
}
