<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests;

use app\controller\RefundController;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use tests\Support\HttpRequestMock;

/**
 * #14 修复回归：审批退款必须由 service 真正执行。
 * service 不可用/业务失败时，admin 绝不能把本地 refunds.status 改成 refunded。
 */
final class RefundControllerTest extends TestCase
{
    private const REFUND_ID = 55;

    protected function setUp(): void
    {
        parent::setUp();

        // /internal/v1 强制令牌：缺失时控制器拒绝审批，测试统一注入
        putenv('INTERNAL_MONITOR_TOKEN=test-internal-token');

        // support\Log 依赖 webman config('log')，单测环境没有配置：注入空 handler 的 logger
        (new \ReflectionProperty(\support\Log::class, 'instance'))
            ->setValue(null, ['default' => new \Monolog\Logger('default', [])]);

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'mysql');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->schema('mysql')->create('refunds', function (Blueprint $t) {
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

        Capsule::connection('mysql')->table('refunds')->insert([
            'id' => self::REFUND_ID, 'order_id' => 1, 'user_id' => 100,
            'amount' => '10.0000', 'status' => 'pending',
        ]);
    }

    protected function tearDown(): void
    {
        putenv('INTERNAL_MONITOR_TOKEN');
        parent::tearDown();
    }

    public function testMissingInternalTokenFailsClosedWithoutCallingService(): void
    {
        putenv('INTERNAL_MONITOR_TOKEN');
        // MockHandler 为空：若仍发起 HTTP 会抛异常 -> fail，断言失败文案即证明未调用
        $controller = $this->controllerWith([]);

        $body = $this->update($controller);
        $this->assertSame(1, $body['code']);
        $this->assertSame('内部退款令牌未配置', $body['msg']);
        $this->assertSame('pending', $this->refundStatus());
    }

    public function testServiceUnavailableDoesNotMarkRefunded(): void
    {
        $controller = $this->controllerWith([
            new ConnectException('connection refused', new Request('POST', 'http://127.0.0.1:8787/internal/v1/refunds/execute')),
        ]);

        $body = $this->update($controller);
        $this->assertSame(1, $body['code']);
        $this->assertSame('退款服务不可用，请稍后重试', $body['msg']);
        $this->assertSame('pending', $this->refundStatus());
    }

    public function testBusinessFailureDoesNotMarkRefunded(): void
    {
        $controller = $this->controllerWith([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
                'code' => 409,
                'message' => 'Order cannot be refunded',
                'data' => null,
            ])),
        ]);

        $body = $this->update($controller);
        $this->assertSame(1, $body['code']);
        $this->assertSame('Order cannot be refunded', $body['msg']);
        $this->assertSame('pending', $this->refundStatus());
    }

    public function testServiceErrorStatusDoesNotMarkRefunded(): void
    {
        $controller = $this->controllerWith([new GuzzleResponse(403, [], '{"error":"Forbidden"}')]);

        $body = $this->update($controller);
        $this->assertSame(1, $body['code']);
        $this->assertSame('退款服务不可用，请稍后重试', $body['msg']);
        $this->assertSame('pending', $this->refundStatus());
    }

    public function testServiceFailureRestoresApprovalFields(): void
    {
        $controller = $this->controllerWith([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
                'code' => 409,
                'message' => 'Refund amount must equal paid amount',
                'data' => null,
            ])),
        ]);

        $body = $this->update($controller, ['amount' => '12.5000', 'reason' => '调整金额']);

        $this->assertSame(1, $body['code']);
        $row = Capsule::connection('mysql')->table('refunds')->where('id', self::REFUND_ID)->first();
        // 远程失败恢复审批前金额/原因，避免本地与真实退款结果不一致
        $this->assertSame(0, bccomp((string) $row->amount, '10.0000', 4));
        $this->assertNull($row->reason);
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->order_id);
        $this->assertSame(100, (int) $row->user_id);
    }

    public function testAlreadyRefundedIsIdempotentWithoutCallingService(): void
    {
        Capsule::connection('mysql')->table('refunds')->where('id', self::REFUND_ID)->update(['status' => 'refunded']);
        // MockHandler 为空：若仍发起 HTTP 会抛异常 -> fail，断言成功即证明未调用
        $controller = $this->controllerWith([]);

        $body = $this->update($controller);
        $this->assertSame(0, $body['code']);
        $this->assertSame('refunded', $this->refundStatus());
    }

    private function controllerWith(array $responses): RefundController
    {
        return new class($responses) extends RefundController {
            public function __construct(private array $responses)
            {
                parent::__construct();
            }

            protected function refundHttpClient(): Client
            {
                return new Client(['handler' => HandlerStack::create(new MockHandler($this->responses))]);
            }
        };
    }

    private function update(RefundController $controller, array $extra = []): array
    {
        $response = $controller->update(new HttpRequestMock([], $extra + [
            'id' => hashids_encode(self::REFUND_ID),
            'status' => 'refunded',
        ], 'POST'));

        return json_decode((string) $response->rawBody(), true);
    }

    private function refundStatus(): string
    {
        return (string) Capsule::connection('mysql')->table('refunds')
            ->where('id', self::REFUND_ID)
            ->value('status');
    }
}
