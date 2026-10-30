<?php

namespace Tests\order;

use App\order\controller\InternalRefundController;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Tests\support\RequestMock;

/**
 * 内部退款端点契约：参数校验 400、资源不存在 404、业务拒绝 409。
 * 不触达 Stripe（409 在状态校验阶段即返回）。
 */
final class InternalRefundControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('order_no');
            $t->unsignedBigInteger('user_id');
            $t->string('status');
            $t->string('currency', 3)->default('USD');
            $t->decimal('total', 14, 4);
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        $schema->create('refunds', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('user_id');
            $t->decimal('amount', 12, 4);
            $t->string('reason')->nullable();
            $t->string('status');
            $t->unsignedBigInteger('handled_by')->nullable();
            $t->string('reject_reason')->nullable();
            $t->timestamps();
        });
        // executePending 会遍历 items 并查询成功支付交易
        $schema->create('order_items', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('sku_id')->nullable();
        });
        $schema->create('payment_transactions', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('channel_id');
            $t->decimal('amount', 12, 4);
            $t->string('currency', 3);
            $t->string('transaction_no');
            $t->string('status');
        });
    }

    public function testRejectsInvalidParameters(): void
    {
        $controller = new InternalRefundController();
        $cases = [
            [],
            ['refund_id' => 'abc', 'operator_id' => 1],
            ['refund_id' => 0, 'operator_id' => 1],
            ['refund_id' => 1, 'operator_id' => 0],
            ['refund_id' => 1],
        ];

        foreach ($cases as $post) {
            $body = $this->body($controller->execute(new RequestMock([], $post)));
            $this->assertSame(400, $body['code'], json_encode($post));
        }
    }

    public function testRefundNotFound(): void
    {
        $body = $this->body((new InternalRefundController())->execute(
            new RequestMock([], ['refund_id' => 999, 'operator_id' => 1])
        ));
        $this->assertSame(404, $body['code']);
    }

    public function testOrderNotFound(): void
    {
        Capsule::table('refunds')->insert([
            'id' => 10, 'order_id' => 999, 'user_id' => 100, 'amount' => '10.0000',
            'status' => 'pending',
        ]);

        $body = $this->body((new InternalRefundController())->execute(
            new RequestMock([], ['refund_id' => 10, 'operator_id' => 1])
        ));
        $this->assertSame(404, $body['code']);
    }

    public function testFinalStatusRefundRejectedWith409(): void
    {
        Capsule::table('orders')->insert([
            'id' => 1, 'order_no' => 'ORD-1', 'user_id' => 100, 'status' => 'paid',
            'currency' => 'USD', 'total' => '10.0000', 'paid_at' => date('Y-m-d H:i:s'),
        ]);
        Capsule::table('refunds')->insert([
            'id' => 11, 'order_id' => 1, 'user_id' => 100, 'amount' => '10.0000',
            'status' => 'refunded',
        ]);

        $body = $this->body((new InternalRefundController())->execute(
            new RequestMock([], ['refund_id' => 11, 'operator_id' => 1])
        ));
        $this->assertSame(409, $body['code']);
        $this->assertSame('Refund cannot be executed in current status', $body['message']);
    }

    public function testNoSuccessfulTransactionReturns502AndMarksFailed(): void
    {
        Capsule::table('orders')->insert([
            'id' => 2, 'order_no' => 'ORD-2', 'user_id' => 100, 'status' => 'paid',
            'currency' => 'USD', 'total' => '10.0000', 'paid_at' => date('Y-m-d H:i:s'),
        ]);
        Capsule::table('refunds')->insert([
            'id' => 12, 'order_id' => 2, 'user_id' => 100, 'amount' => '10.0000',
            'status' => 'pending',
        ]);

        $body = $this->body((new InternalRefundController())->execute(
            new RequestMock([], ['refund_id' => 12, 'operator_id' => 1])
        ));
        $this->assertSame(502, $body['code']);
        $this->assertSame('failed', Capsule::table('refunds')->where('id', 12)->value('status'));
    }

    private function body($response): array
    {
        return json_decode($response->rawBody(), true);
    }
}
