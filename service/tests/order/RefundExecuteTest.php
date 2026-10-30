<?php

namespace Tests\order;

use App\order\model\Order;
use App\order\model\Refund;
use App\order\service\RefundService;
use Erikwang2013\WebmanScout\ModelObserver;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Redis as RedisFacade;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;

/**
 * #14 修复回归：executePending 只接受 pending/failed 行，金额必须与成功支付全额相等，
 * Stripe idempotency_key 走第二个 $opts 参数且按订单维度去重。
 */
final class RefundExecuteTest extends TestCase
{
    private const ORDER_ID = 8001;
    private const NO_TXN_ORDER_ID = 8002;

    protected function setUp(): void
    {
        parent::setUp();

        // Order 使用 Scout Searchable，模型 update 会触发索引同步；单测禁用
        ModelObserver::disableSyncingFor(Order::class);
        // WebhookDispatcher 仅在有注册 URL 时投递，swap 空实现避免真实 Redis。
        // get() 一并提供：facade 假实现会泄漏到后续测试（如汇率读取）
        RedisFacade::swap(new class {
            public function smembers(string $key): array { return []; }
            public function get(string $key) { return null; }
        });

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
        // assertRefundable 遍历 items；本测试订单无 items，表存在即可
        $schema->create('order_items', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('sku_id')->nullable();
        });
        $schema->create('refunds', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('user_id');
            $t->decimal('amount', 12, 4);
            $t->string('reason')->nullable();
            $t->string('status');
            $t->string('stripe_refund_id')->nullable();
            $t->unsignedBigInteger('handled_by')->nullable();
            $t->string('reject_reason')->nullable();
            $t->timestamps();
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

        Capsule::table('orders')->insert([
            [
                'id' => self::ORDER_ID, 'order_no' => 'ORD-8001', 'user_id' => 100,
                'status' => 'paid', 'currency' => 'USD', 'total' => '100.0000',
                'paid_at' => date('Y-m-d H:i:s'),
            ],
            [
                'id' => self::NO_TXN_ORDER_ID, 'order_no' => 'ORD-8002', 'user_id' => 100,
                'status' => 'paid', 'currency' => 'USD', 'total' => '100.0000',
                'paid_at' => date('Y-m-d H:i:s'),
            ],
        ]);
        Capsule::table('payment_transactions')->insert([
            'id' => 1, 'order_id' => self::ORDER_ID, 'user_id' => 100, 'channel_id' => 1,
            'amount' => '100.0000', 'currency' => 'USD',
            'transaction_no' => 'pi_paid_1', 'status' => 'success',
        ]);
    }

    protected function tearDown(): void
    {
        ModelObserver::enableSyncingFor(Order::class);
        parent::tearDown();
    }

    public function testRejectsPartialRefundAndMarksFailed(): void
    {
        try {
            (new RefundService())->execute($this->order(self::ORDER_ID), '99.9999', 'test', 42);
            $this->fail('partial refund should be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Refund amount must equal paid amount', $e->getMessage());
        }

        $row = Capsule::table('refunds')->orderBy('id')->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame('Refund amount must equal paid amount', $row->reject_reason);
    }

    public function testRejectsInvalidAmounts(): void
    {
        $cases = [
            '0'         => 'Refund amount must be positive',
            '-1.00'     => 'Invalid refund amount',
            'abc'       => 'Invalid refund amount',
            '100.00000' => 'Invalid refund amount',
            '100.0001'  => 'Refund amount must equal paid amount',
        ];

        foreach ($cases as $amount => $message) {
            try {
                (new RefundService())->execute($this->order(self::ORDER_ID), (string) $amount, 'test', 42);
                $this->fail("amount {$amount} should be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function testRejectsNonPositiveOperatorBeforeCreatingRefund(): void
    {
        try {
            (new RefundService())->execute($this->order(self::ORDER_ID), '100.0000', 'x', 0);
            $this->fail('operator 0 should be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Refund operator is required', $e->getMessage());
        }
        // 非法 operator 不能留下 pending 行
        $this->assertSame(0, Capsule::table('refunds')->count());

        $refund = Refund::create([
            'order_id' => self::ORDER_ID, 'user_id' => 100, 'amount' => '100.0000',
            'reason' => 'x', 'status' => 'pending',
        ]);
        try {
            (new RefundService())->executePending($refund, 0);
            $this->fail('executePending should reject operator 0');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Refund operator is required', $e->getMessage());
        }
        $this->assertSame('pending', (string) Capsule::table('refunds')->where('id', $refund->id)->value('status'));
    }

    public function testNoSuccessfulTransactionMarksRefundFailed(): void
    {
        try {
            (new RefundService())->execute($this->order(self::NO_TXN_ORDER_ID), '100.0000', 'test', 42);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No successful payment transaction', $e->getMessage());
        }

        $row = Capsule::table('refunds')->where('order_id', self::NO_TXN_ORDER_ID)->first();
        $this->assertSame('failed', $row->status);
    }

    public function testNonRefundableOrderMarksRefundFailedAndFreesPendingSlot(): void
    {
        Capsule::table('orders')->insert([
            'id' => 8003, 'order_no' => 'ORD-8003', 'user_id' => 100,
            'status' => 'pending', 'currency' => 'USD', 'total' => '100.0000',
        ]);

        try {
            (new RefundService())->execute($this->order(8003), '100.0000', 'test', 42);
            $this->fail('non-refundable order should be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Order cannot be refunded', $e->getMessage());
        }

        // 不可退时 refunds 行必须落 failed，释放 pending_order_id 唯一键供修复后重新发起
        $row = Capsule::table('refunds')->where('order_id', 8003)->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame('Order cannot be refunded', $row->reject_reason);
    }

    public function testRefundInFinalStatusCannotExecute(): void
    {
        $refund = Refund::create([
            'order_id' => self::ORDER_ID, 'user_id' => 100, 'amount' => '100.0000',
            'reason' => 'x', 'status' => 'refunded',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refund cannot be executed in current status');
        (new RefundService())->executePending($refund, 42);
    }

    public function testSuccessfulRefundPassesOrderIdempotencyKeyAsOptions(): void
    {
        $stripe = $this->fakeStripe();
        $refund = (new RefundService($stripe))->execute(
            $this->order(self::ORDER_ID),
            '100.0000',
            'customer request',
            42
        );

        $this->assertSame('refunded', $refund->status);
        $this->assertSame(42, (int) $refund->handled_by);
        $this->assertSame('re_test_1', (string) $refund->stripe_refund_id);
        $this->assertSame('refunded', (string) Capsule::table('orders')->where('id', self::ORDER_ID)->value('status'));

        $service = $stripe->refunds();
        // idempotency_key 必须走第二个 $opts 参数，且按订单维度去重
        $this->assertSame('refund_order_' . self::ORDER_ID, $service->lastOpts['idempotency_key'] ?? null);
        $this->assertArrayNotHasKey('idempotency_key', $service->lastParams);
        $this->assertSame('pi_paid_1', $service->lastParams['payment_intent']);
        $this->assertSame(10000, $service->lastParams['amount']);
    }

    private function order(int $id): Order
    {
        return Order::findOrFail($id);
    }

    private function fakeStripe(): StripeClient
    {
        $service = new class {
            public ?array $lastParams = null;
            public ?array $lastOpts = null;

            public function create(array $params, ?array $opts = null): object
            {
                $this->lastParams = $params;
                $this->lastOpts = $opts;
                return (object) ['id' => 're_test_1'];
            }
        };

        return new class($service) extends StripeClient {
            public function __construct(private object $refundService)
            {
                parent::__construct('sk_test_fake');
            }

            public function getService($name)
            {
                return $name === 'refunds' ? $this->refundService : parent::getService($name);
            }

            public function refunds(): object
            {
                return $this->refundService;
            }
        };
    }
}
