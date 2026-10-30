<?php

namespace Tests\payment;

use App\payment\controller\PaymentController;
use App\payment\model\PaymentChannel;
use App\payment\service\channels\StripeChannel;
use Common\hashid\HashidService;
use Erikwang2013\Hashids\HashidsFactory;
use Erikwang2013\Hashids\HashidsManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;
use Tests\support\RequestMock;

/**
 * #10 修复回归：支付先落 pending claim（事务内）再调 Stripe（事务外）。
 * - 同一订单重复发起只产生一条 claim；
 * - Stripe 成功回填 transaction_no；
 * - Stripe 失败把交易标记 failed。
 */
final class PaymentControllerTest extends TestCase
{
    private const ORDER_ID = 7001;
    private const USER_ID  = 100;

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
            $t->decimal('exchange_rate', 12, 6)->default('1.000000');
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        $schema->create('payment_channels', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('code');
            $t->string('status')->default('active');
            $t->boolean('is_visible')->default(true);
            $t->text('currency_support')->nullable();
            $t->text('visible_regions')->nullable();
            $t->decimal('min_amount', 12, 4)->nullable();
            $t->decimal('max_amount', 12, 4)->nullable();
            $t->text('fee_config')->nullable();
        });
        $schema->create('payment_transactions', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('channel_id');
            $t->decimal('amount', 12, 4);
            $t->string('currency', 3);
            $t->decimal('exchange_rate', 12, 6)->default('1.000000');
            $t->decimal('channel_fee', 12, 4)->default('0');
            $t->string('transaction_no')->unique();
            $t->string('status');
            $t->timestamp('callback_at')->nullable();
            $t->timestamps();
        });

        Capsule::table('orders')->insert([
            'id' => self::ORDER_ID, 'order_no' => 'ORD-7001', 'user_id' => self::USER_ID,
            'status' => 'pending', 'currency' => 'USD', 'total' => '19.9900',
        ]);
        Capsule::table('payment_channels')->insert([
            'id' => 1, 'code' => 'stripe', 'status' => 'active', 'is_visible' => 1,
            'currency_support' => json_encode(['USD']), 'fee_config' => '{}',
        ]);

        $config = [
            'default' => 'main',
            'connections' => ['main' => [
                'salt'     => getenv('HASHIDS_SALT') ?: 'test-salt',
                'length'   => (int) (getenv('HASHIDS_LENGTH') ?: 12),
                'alphabet' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
            ]],
        ];
        (new \ReflectionClass(HashidService::class))->getProperty('manager')
            ->setValue(null, new HashidsManager($config, new HashidsFactory()));

        // support\Log 依赖 webman config('log')，单测环境没有配置：注入空 handler 的 logger
        (new \ReflectionProperty(\support\Log::class, 'instance'))
            ->setValue(null, ['default' => new \Monolog\Logger('default', [])]);
    }

    public function testDuplicatePayProducesSingleClaimAndSuccessUpdatesTransactionNo(): void
    {
        $stripe = $this->stripeReturning(
            (object) ['id' => 'pi_test_123', 'client_secret' => 'cs_test_123']
        );
        $controller = $this->controller($stripe);
        $id = HashidService::encode(self::ORDER_ID);

        $body = $this->body($controller->pay($this->request(), $id));
        $this->assertSame(0, $body['code']);
        $this->assertSame('pi_test_123', $body['data']['transaction_id']);
        // idempotency_key 必须走 Stripe 第二个 $opts 参数，放 params 里 SDK 会忽略
        $this->assertSame('order_' . self::ORDER_ID, $stripe->intents()->lastOpts['idempotency_key'] ?? null);
        // Stripe 外部调用必须在 DB 事务外（事务内为 1）
        $this->assertSame(0, $stripe->intents()->transactionLevel);

        $rows = Capsule::table('payment_transactions')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('pi_test_123', $rows[0]->transaction_no);
        $this->assertSame('pending', $rows[0]->status);

        // 第二次发起：命中 pending claim，拒绝且不新增记录
        $body = $this->body($controller->pay($this->request(), $id));
        $this->assertSame(422, $body['code']);
        $this->assertSame(1, Capsule::table('payment_transactions')->count());
    }

    public function testStripeFailureMarksTransactionFailed(): void
    {
        $stripe = $this->stripeReturning(null, ApiConnectionException::factory('card declined'));
        $controller = $this->controller($stripe);

        $body = $this->body($controller->pay($this->request(), HashidService::encode(self::ORDER_ID)));
        $this->assertSame(500, $body['code']);
        $this->assertSame(0, $stripe->intents()->transactionLevel);

        $row = Capsule::table('payment_transactions')->first();
        $this->assertSame('failed', $row->status);
        // 失败前保留 claim 占位 transaction_no
        $this->assertStringStartsWith('pending:', $row->transaction_no);
    }

    /**
     * #10 边界：已有 success 交易时重复 pay 直接拒绝，且不调用 Stripe。
     */
    public function testSuccessfulTransactionBlocksSecondPay(): void
    {
        $this->seedTransaction('pi_paid', 'success');
        $stripe = $this->stripeReturning((object) ['id' => 'pi_new', 'client_secret' => 'cs_new']);
        $controller = $this->controller($stripe);

        $body = $this->body($controller->pay($this->request(), HashidService::encode(self::ORDER_ID)));

        $this->assertSame(422, $body['code']);
        $this->assertSame(0, $stripe->intents()->calls);
        $this->assertSame(1, Capsule::table('payment_transactions')->count());
    }

    /**
     * #10 边界：failed 交易允许重试；新 claim 快照订单金额/币种/用户。
     */
    public function testFailedTransactionAllowsRetryAndSnapshotsOrder(): void
    {
        $this->seedTransaction('pending:old', 'failed');
        $stripe = $this->stripeReturning((object) ['id' => 'pi_retry', 'client_secret' => 'cs_retry']);
        $controller = $this->controller($stripe);

        $body = $this->body($controller->pay($this->request(), HashidService::encode(self::ORDER_ID)));

        $this->assertSame(0, $body['code']);
        $this->assertSame(0, $stripe->intents()->transactionLevel);

        $rows = Capsule::table('payment_transactions')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $claim = $rows[1];
        $this->assertSame('pending', $claim->status);
        $this->assertSame('pi_retry', $claim->transaction_no);
        $this->assertSame(0, bccomp((string) $claim->amount, '19.9900', 4));
        $this->assertSame('USD', $claim->currency);
        $this->assertSame(self::USER_ID, (int) $claim->user_id);
    }

    private function seedTransaction(string $transactionNo, string $status): void
    {
        Capsule::table('payment_transactions')->insert([
            'order_id'       => self::ORDER_ID,
            'user_id'        => self::USER_ID,
            'channel_id'     => 1,
            'amount'         => '19.9900',
            'currency'       => 'USD',
            'exchange_rate'  => '1.000000',
            'transaction_no' => $transactionNo,
            'status'         => $status,
        ]);
    }

    private function body($response): array
    {
        return json_decode($response->rawBody(), true);
    }

    private function request(): RequestMock
    {
        $request = new RequestMock([], ['channel_id' => 1]);
        $request->userId = self::USER_ID;
        return $request;
    }

    private function controller(StripeClient $stripe): PaymentController
    {
        return new class($stripe) extends PaymentController {
            public function __construct(private StripeClient $stripe)
            {
            }

            protected function makeStripeChannel(PaymentChannel $channel): StripeChannel
            {
                return new StripeChannel($channel, $this->stripe);
            }
        };
    }

    private function stripeReturning(?\stdClass $intent = null, ?\Throwable $error = null): StripeClient
    {
        $service = new class($intent, $error) {
            public ?array $lastOpts = null;
            public ?int $transactionLevel = null;
            public int $calls = 0;

            public function __construct(private ?\stdClass $intent, private ?\Throwable $error)
            {
            }

            public function create(array $params, ?array $opts = null): \stdClass
            {
                $this->calls++;
                $this->lastOpts = $opts;
                $this->transactionLevel = Capsule::connection()->transactionLevel();
                if ($this->error) {
                    throw $this->error;
                }
                return $this->intent;
            }
        };

        return new class($service) extends StripeClient {
            public function __construct(private object $intents)
            {
                parent::__construct('sk_test_fake');
            }

            public function getService($name)
            {
                return $name === 'paymentIntents' ? $this->intents : parent::getService($name);
            }

            public function intents(): object
            {
                return $this->intents;
            }
        };
    }
}
