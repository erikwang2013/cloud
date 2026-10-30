<?php

namespace Tests\provisioning;

use App\provisioning\model\ProvisionResult;
use App\provisioning\model\ProvisionTask;
use App\provisioning\queue\ProvisionWorker;
use App\provisioning\service\ProviderFactory;
use App\provisioning\service\ProviderInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * #5 修复回归：任务通过原子 update 抢占（pending -> processing），
 * 两个消费者不会处理同一任务；重试回到 pending 并设置退避时间。
 */
final class ProvisionWorkerClaimTest extends TestCase
{
    private ProvisionWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        // alertFailure 走 Illuminate Event facade（生产在 support/bootstrap.php 设置根容器）
        \Illuminate\Support\Facades\Facade::setFacadeApplication($capsule->getContainer());

        $capsule->schema()->create('provision_tasks', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('order_item_id');
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->string('product_type');
            $t->string('provider');
            $t->unsignedBigInteger('region_id');
            $t->string('action')->default('create');
            $t->string('status');
            $t->text('params')->nullable();
            $t->integer('retry_count')->default(0);
            $t->text('last_error')->nullable();
            $t->dateTime('next_retry_at');
            $t->timestamps();
        });

        $capsule->schema()->create('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('order_no')->nullable();
            $t->unsignedBigInteger('user_id');
            $t->string('status');
            $t->string('currency', 3)->default('USD');
            $t->decimal('total', 14, 4)->default('0');
            $t->timestamps();
        });
        $capsule->schema()->create('order_items', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('sku_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->decimal('total_price', 14, 4)->default('0');
            $t->timestamps();
        });
        $capsule->schema()->create('order_timeline', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->string('status');
            $t->string('operator')->nullable();
            $t->text('remark')->nullable();
            $t->timestamps();
        });
        $capsule->schema()->create('resources', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_item_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('type');
            $t->string('provider');
            $t->unsignedBigInteger('region_id')->nullable();
            $t->string('status');
            $t->text('specs')->nullable();
            $t->dateTime('provisioned_at')->nullable();
            $t->dateTime('expired_at')->nullable();
            $t->timestamps();
        });

        // support\Log 依赖 webman config('log')，单测环境没有配置：注入空 handler 的 logger
        (new \ReflectionProperty(\support\Log::class, 'instance'))
            ->setValue(null, ['default' => new \Monolog\Logger('default', [])]);

        $this->worker = new ProvisionWorker();
    }

    public function testClaimIsExclusive(): void
    {
        $this->seedTask(1, 'pending', '2026-01-01 00:00:00');

        $first = $this->claim(1, true);
        $this->assertNotNull($first);
        $this->assertSame('processing', $first->status);

        // 第二个消费者抢同一任务：affected=0，拿到 null
        $this->assertNull($this->claim(1, true));
        $this->assertSame('processing', Capsule::table('provision_tasks')->value('status'));
    }

    public function testClaimSkipsTaskNotYetDue(): void
    {
        $this->seedTask(2, 'pending', date('Y-m-d H:i:s', time() + 3600));

        $this->assertNull($this->claim(2, true));
        $this->assertSame('pending', Capsule::table('provision_tasks')->value('status'));
    }

    public function testRetrySetsBackoffAndReturnsToPending(): void
    {
        $this->seedTask(3, 'processing', '2026-01-01 00:00:00');
        $task = ProvisionTask::find(3);

        $this->retry($task, 'provider timeout');

        $row = Capsule::table('provision_tasks')->where('id', 3)->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->retry_count);
        $this->assertSame('provider timeout', $row->last_error);
        // 首次重试 1 分钟后（允许数秒执行误差）
        $this->assertGreaterThan(time() + 50, strtotime($row->next_retry_at));
        $this->assertLessThan(time() + 70, strtotime($row->next_retry_at));
    }

    public function testRetryFailsAtMaxAttempts(): void
    {
        $this->seedTask(4, 'processing', '2026-01-01 00:00:00', 6);
        $task = ProvisionTask::find(4);

        $this->retry($task, 'still failing');

        $row = Capsule::table('provision_tasks')->where('id', 4)->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame(7, (int) $row->retry_count);
    }

    protected function tearDown(): void
    {
        ProviderFactory::clear();
        // 每个用例 setUp 都会换新 dispatcher；已 boot 模型不会重绑 creating 监听（Snowflake ID 会变 null），
        // 且伪造的 redis 绑定会通过 Facade 缓存泄漏到后续测试。这里统一清理进程级状态。
        \Illuminate\Database\Eloquent\Model::clearBootedModels();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    /**
     * #5 边界：交付成功后、webhook 等副作用抛错（Redis 抖动）时，
     * 已完成的任务不得被重新置为 pending，否则会再次调用 provider 重复创建远端资源。
     */
    public function testSideEffectFailureAfterSuccessDoesNotRequeueTask(): void
    {
        $this->seedOrderWithItems(10, [20]);
        $this->seedResource(500, 20);
        $this->seedTask(5, 'processing', '2026-01-01 00:00:00', 0, 500, 'fake');
        $this->registerProvider(ProvisionResult::success(['ip_address' => '10.0.0.1']));

        // WebhookDispatcher 直接调用 Facade Redis 且无 try/catch；用抛错的假实现模拟 Redis 抖动
        \Illuminate\Support\Facades\Facade::getFacadeApplication()->instance('redis', new class {
            public function smembers(string $key): array
            {
                throw new \RuntimeException('redis down');
            }
        });

        $this->process(ProvisionTask::find(5));

        $row = Capsule::table('provision_tasks')->where('id', 5)->first();
        $this->assertSame('completed', $row->status);
        $this->assertSame(0, (int) $row->retry_count);
        $this->assertSame('active', Capsule::table('resources')->where('id', 500)->value('status'));
        $this->assertSame(1, Capsule::table('resources')->count());
        // webhook/WS 都失败时 checkOrderComplete 仍必须执行：订单置 completed 并留时间线
        $this->assertSame('completed', Capsule::table('orders')->where('id', 10)->value('status'));
        $this->assertSame(
            'completed',
            Capsule::table('order_timeline')->where('order_id', 10)->value('status')
        );
    }

    /**
     * #5 边界：重试时复用 task.resource_id，不新建资源行。
     */
    public function testRetryReusesExistingResource(): void
    {
        $this->seedOrderWithItems(10, [20]);
        $this->seedResource(500, 20);
        $this->seedTask(6, 'processing', '2026-01-01 00:00:00', 0, 500, 'fake');
        $this->registerProvider(ProvisionResult::retryable('provider timeout'));

        $this->process(ProvisionTask::find(6));

        $row = Capsule::table('provision_tasks')->where('id', 6)->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->retry_count);
        $this->assertSame(500, (int) $row->resource_id);
        $this->assertSame(1, Capsule::table('resources')->count());
        $this->assertSame('creating', Capsule::table('resources')->where('id', 500)->value('status'));
    }

    private function process(ProvisionTask $task): void
    {
        (new \ReflectionMethod(ProvisionWorker::class, 'process'))->invoke($this->worker, $task);
    }

    private function registerProvider(ProvisionResult $result): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('create')->willReturn($result);
        ProviderFactory::register('server', 'fake', fn() => $provider);
    }

    private function seedOrderWithItems(int $orderId, array $itemIds): void
    {
        Capsule::table('orders')->insert([
            'id' => $orderId, 'order_no' => 'ORD-' . $orderId, 'user_id' => 100,
            'status' => 'paid', 'currency' => 'USD', 'total' => '10.0000',
        ]);
        foreach ($itemIds as $itemId) {
            Capsule::table('order_items')->insert([
                'id' => $itemId, 'order_id' => $orderId, 'total_price' => '10.0000',
            ]);
        }
    }

    private function seedResource(int $id, int $orderItemId): void
    {
        Capsule::table('resources')->insert([
            'id' => $id, 'order_item_id' => $orderItemId, 'user_id' => 100,
            'type' => 'server', 'provider' => 'fake', 'status' => 'creating',
        ]);
    }

    private function claim(int $taskId, bool $requireDue): ?ProvisionTask
    {
        $method = new \ReflectionMethod(ProvisionWorker::class, 'claim');
        return $method->invoke($this->worker, $taskId, $requireDue);
    }

    private function retry(ProvisionTask $task, string $error): void
    {
        $method = new \ReflectionMethod(ProvisionWorker::class, 'retryOrFail');
        $method->invoke($this->worker, $task, $error);
    }

    private function seedTask(
        int $id,
        string $status,
        string $nextRetryAt,
        int $retryCount = 0,
        ?int $resourceId = null,
        string $provider = 'proxmox'
    ): void {
        Capsule::table('provision_tasks')->insert([
            'id'            => $id,
            'order_id'      => 10,
            'order_item_id' => 20,
            'resource_id'   => $resourceId,
            'product_type'  => 'server',
            'provider'      => $provider,
            'region_id'     => 1,
            'action'        => 'create',
            'status'        => $status,
            'retry_count'   => $retryCount,
            'next_retry_at' => $nextRetryAt,
        ]);
    }
}
