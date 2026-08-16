<?php

namespace Tests\Supplier;

use App\Supplier\Service\SupplierRatingService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

final class SupplierRatingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // 裸 Eloquent 环境无事件调度器，Snowflake creating 钩子不生效；挂上 dispatcher。
        if (!\Illuminate\Database\Eloquent\Model::getEventDispatcher()) {
            \Illuminate\Database\Eloquent\Model::setEventDispatcher(
                new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container)
            );
        }

        $schema = $capsule->schema();
        $schema->create('orders', function ($t) {
            $t->bigIncrements('id');
            $t->string('order_no', 32);
            $t->unsignedBigInteger('user_id');
            $t->string('type', 32)->default('new');
            $t->string('status', 32)->default('pending');
            $t->string('currency', 3)->default('USD');
            $t->decimal('subtotal', 12, 4)->default(0);
            $t->decimal('discount', 12, 4)->default(0);
            $t->decimal('tax', 12, 4)->default(0);
            $t->decimal('total', 12, 4)->default(0);
            $t->decimal('exchange_rate', 12, 6)->default(1);
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        $schema->create('suppliers', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->decimal('rating_avg', 4, 2)->default(0);
            $t->unsignedInteger('rating_count')->default(0);
            $t->timestamps();
        });
        $schema->create('products', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->string('slug', 255);
            $t->string('status', 32)->default('active');
            $t->timestamps();
        });
        $schema->create('product_skus', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('sku_code', 128);
            $t->string('status', 32)->default('active');
            $t->timestamps();
        });
        $schema->create('order_items', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('sku_id');
            $t->unsignedBigInteger('product_id');
            $t->integer('quantity')->default(1);
            $t->decimal('unit_price', 12, 4)->default(0);
            $t->decimal('total_price', 12, 4)->default(0);
            $t->string('status', 32)->default('pending');
            $t->timestamps();
        });
        $schema->create('supplier_ratings', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('supplier_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('order_id');
            $t->tinyInteger('rating');
            $t->tinyInteger('quality')->default(0);
            $t->tinyInteger('support')->default(0);
            $t->tinyInteger('delivery_speed')->default(0);
            $t->tinyInteger('value')->default(0);
            $t->text('content')->nullable();
            $t->string('status', 16)->default('published');
            $t->timestamps();
            $t->unique(['user_id', 'order_id']);
        });
    }

    private function seedOrder(int $id, int $userId, string $status): void
    {
        Capsule::table('orders')->insert([
            'id'         => $id,
            'order_no'   => 'ORD' . $id,
            'user_id'    => $userId,
            'status'     => $status,
            'total'      => '10.0000',
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ]);
    }

    private function seedSupplier(int $id): void
    {
        Capsule::table('suppliers')->insert([
            'id'         => $id,
            'user_id'    => 999,
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ]);
    }

    private function seedProductChain(int $orderId, int $productId, int $supplierId): void
    {
        Capsule::table('products')->insert([
            'id'         => $productId,
            'supplier_id' => $supplierId,
            'slug'       => 'p' . $productId,
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ]);
        Capsule::table('product_skus')->insert([
            'id'         => $productId,
            'product_id' => $productId,
            'sku_code'   => 'SKU' . $productId,
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ]);
        Capsule::table('order_items')->insert([
            'order_id'    => $orderId,
            'sku_id'      => $productId,
            'product_id'  => $productId,
            'unit_price'  => '10.0000',
            'total_price' => '10.0000',
            'created_at'  => '2026-08-01 10:00:00',
            'updated_at'  => '2026-08-01 10:00:00',
        ]);
    }

    private function rate(int $userId, int $orderId, int $supplierId = 1, array $data = ['rating' => 4]): \App\Supplier\Model\SupplierRating
    {
        return (new SupplierRatingService())->rate($userId, $supplierId, $orderId, $data);
    }

    public function testRateValidPaidOrderCreatesRatingAndRecomputesAvg(): void
    {
        $this->seedOrder(1, 42, 'paid');
        $this->seedSupplier(1);
        $this->seedProductChain(1, 10, 1);

        $rating = $this->rate(42, 1);

        $this->assertSame(42, (int) $rating->user_id);
        $this->assertSame(4, (int) $rating->rating);
        $this->assertSame('published', $rating->status);
        $this->assertSame(1, Capsule::table('supplier_ratings')->count());

        $supplier = Capsule::table('suppliers')->where('id', 1)->first();
        $this->assertSame(4.0, (float) $supplier->rating_avg);
        $this->assertSame(1, (int) $supplier->rating_count);
    }

    public function testRateCompletedOrderAllowed(): void
    {
        $this->seedOrder(1, 42, 'completed');
        $this->seedSupplier(1);
        $this->seedProductChain(1, 10, 1);

        $rating = $this->rate(42, 1);

        $this->assertSame(1, (int) $rating->order_id);
    }

    public function testRateRejectsOrderOfAnotherUser(): void
    {
        $this->seedOrder(1, 43, 'paid');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('your own orders');
        $this->rate(42, 1);
    }

    public function testRateRejectsNonRateableOrderStatus(): void
    {
        $this->seedOrder(1, 42, 'pending');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not allow rating');
        $this->rate(42, 1);
    }

    public function testRateRejectsRefundedOrder(): void
    {
        $this->seedOrder(1, 42, 'refunded');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not allow rating');
        $this->rate(42, 1);
    }

    public function testRateRejectsMissingOrder(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order not found');
        $this->rate(42, 999);
    }

    public function testRateRejectsDuplicateRating(): void
    {
        $this->seedOrder(1, 42, 'paid');
        $this->seedSupplier(1);
        $this->seedProductChain(1, 10, 1);

        $this->rate(42, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already rated');
        $this->rate(42, 1);
        $this->assertSame(1, Capsule::table('supplier_ratings')->count());
    }

    public function testRateRejectsOrderWithoutProductsFromSupplier(): void
    {
        $this->seedOrder(1, 42, 'paid');
        $this->seedSupplier(1);
        $this->seedProductChain(1, 10, 2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not contain products from this supplier');
        $this->rate(42, 1, 1);
    }

    public function testRateRejectsOutOfRangeScore(): void
    {
        $this->seedOrder(1, 42, 'paid');
        $this->seedSupplier(1);
        $this->seedProductChain(1, 10, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('between 1 and 5');
        $this->rate(42, 1, 1, ['rating' => 9]);
    }

    public function testRateAllowsBoundaryScores(): void
    {
        $this->seedOrder(1, 42, 'paid');
        $this->seedSupplier(1);
        $this->seedProductChain(1, 10, 1);

        $rating = $this->rate(42, 1, 1, ['rating' => 1, 'quality' => 5]);

        $this->assertSame(1, (int) $rating->rating);
        $this->assertSame(5, (int) $rating->quality);
    }

    public function testRateAllowsExplicitZeroSubScore(): void
    {
        $this->seedOrder(1, 42, 'paid');
        $this->seedSupplier(1);
        $this->seedProductChain(1, 10, 1);

        $rating = $this->rate(42, 1, 1, ['rating' => 4, 'quality' => 0]);

        $this->assertSame(0, (int) $rating->quality);
    }

    public function testRateRejectsOutOfRangeSubScore(): void
    {
        $this->seedOrder(1, 42, 'paid');
        $this->seedSupplier(1);
        $this->seedProductChain(1, 10, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('between 0 and 5');
        $this->rate(42, 1, 1, ['rating' => 4, 'quality' => 6]);
    }
}
