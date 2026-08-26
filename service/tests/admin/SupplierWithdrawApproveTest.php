<?php

namespace Tests\admin;

use App\admin\controller\SupplierController;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class SupplierWithdrawApproveTest extends TestCase
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

        // WebhookDispatcher 走 Redis facade；测试环境无 Redis，绑定空 stub
        // 使其 dispatch 直接返回（AuditLogger 自身有 try/catch 兜底）。
        $container = new \Illuminate\Container\Container();
        $container->bind('redis', fn() => new class {
            public function smembers($key)
            {
                return [];
            }
        });
        // Redis facade 有 static::$resolvedInstance 缓存，先清再设，避免串用旧实例
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $schema = $capsule->schema();
        $schema->create('supplier_withdraws', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('supplier_id');
            $t->decimal('amount', 14, 4);
            $t->string('method', 20);
            $t->string('status', 20)->default('pending');
            $t->unsignedBigInteger('handled_by')->nullable();
            $t->timestamp('handled_at')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    private function seedWithdraw(int $id, string $status): void
    {
        Capsule::table('supplier_withdraws')->insert([
            'id'         => $id,
            'supplier_id' => 9,
            'amount'     => '50.0000',
            'method'     => 'bank',
            'status'     => $status,
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ]);
    }

    public function testApprovePendingWithdrawCompletesAndRecordsHandler(): void
    {
        $this->seedWithdraw(1, 'pending');

        $response = (new SupplierController())->approveWithdraw((object) ['userId' => 42], 1);
        $body = json_decode($response->rawBody(), true);
        $this->assertSame(0, $body['code']);

        $row = Capsule::table('supplier_withdraws')->where('id', 1)->first();
        $this->assertSame('completed', $row->status);
        $this->assertSame(42, (int) $row->handled_by);
        $this->assertNotNull($row->handled_at);
    }

    public function testApproveAlreadyCompletedWithdrawRejected(): void
    {
        $this->seedWithdraw(1, 'completed');

        $response = (new SupplierController())->approveWithdraw((object) ['userId' => 42], 1);
        $body = json_decode($response->rawBody(), true);
        $this->assertSame(400, $body['code']);
        $this->assertStringContainsString('not pending', $body['message']);

        $row = Capsule::table('supplier_withdraws')->where('id', 1)->first();
        $this->assertSame('completed', $row->status);
        $this->assertNull($row->handled_by);
    }

    public function testApproveProcessingWithdrawRejected(): void
    {
        $this->seedWithdraw(1, 'processing');

        $response = (new SupplierController())->approveWithdraw((object) ['userId' => 42], 1);
        $body = json_decode($response->rawBody(), true);
        $this->assertSame(400, $body['code']);
    }
}
