<?php

namespace Tests\provisioning;

use App\cron\ProvisionTaskRecovery;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * #5 边界：claim 置 processing 后进程失联，超时任务必须被回收重试，避免永久卡死。
 */
final class ProvisionTaskRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // support\Log 依赖 webman config('log')，单测环境没有配置：注入空 handler 的 logger
        (new \ReflectionProperty(\support\Log::class, 'instance'))
            ->setValue(null, ['default' => new \Monolog\Logger('default', [])]);

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // completeFinishedOrders 会查询 orders/items；本组用例无订单，空表即可
        $capsule->schema()->create('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('status');
            $t->timestamps();
        });
        $capsule->schema()->create('order_items', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('order_item_id')->nullable();
        });
        $capsule->schema()->create('order_timeline', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('order_id');
            $t->string('status');
            $t->string('operator')->nullable();
            $t->string('remark')->nullable();
            $t->timestamps();
        });

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
            $t->dateTime('next_retry_at')->nullable();
            $t->timestamps();
        });

        Model::clearBootedModels();
    }

    protected function tearDown(): void
    {
        Model::clearBootedModels();
        parent::tearDown();
    }

    public function testStaleProcessingTaskIsRequeued(): void
    {
        $this->seedTask(1, 'processing', 0, $this->minutesAgo(20));

        $recovery = $this->recovery();
        $recovery->run();

        $row = Capsule::table('provision_tasks')->where('id', 1)->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->retry_count);
        $this->assertStringContainsString('Processing timeout', (string) $row->last_error);
        $this->assertNotNull($row->next_retry_at);
        $this->assertSame([1], $recovery->enqueued);
    }

    public function testFreshProcessingTaskIsLeftRunning(): void
    {
        $this->seedTask(2, 'processing', 0, date('Y-m-d H:i:s'));

        $recovery = $this->recovery();
        $recovery->run();

        $row = Capsule::table('provision_tasks')->where('id', 2)->first();
        $this->assertSame('processing', $row->status);
        $this->assertSame(0, (int) $row->retry_count);
        $this->assertSame([], $recovery->enqueued);
    }

    public function testFinishesOrderWhenAllCreateTasksCompleted(): void
    {
        Capsule::table('orders')->insert([
            'id' => 1, 'status' => 'provisioning', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Capsule::table('order_items')->insert(['id' => 1, 'order_id' => 1]);
        Capsule::table('provision_tasks')->insert([
            'id' => 1, 'order_id' => 1, 'order_item_id' => 1, 'product_type' => 'server',
            'provider' => 'fake', 'region_id' => 1, 'action' => 'create', 'status' => 'completed',
            'retry_count' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->recovery()->run();

        $this->assertSame('completed', (string) Capsule::table('orders')->where('id', 1)->value('status'));
        $this->assertSame(1, Capsule::table('order_timeline')->where('order_id', 1)->count());
    }

    public function testStaleTaskAtRetryLimitFails(): void
    {
        $this->seedTask(3, 'processing', 6, $this->minutesAgo(30));

        $recovery = $this->recovery();
        $recovery->run();

        $row = Capsule::table('provision_tasks')->where('id', 3)->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame(7, (int) $row->retry_count);
        $this->assertSame([], $recovery->enqueued);
    }

    private function recovery(): ProvisionTaskRecovery
    {
        return new class extends ProvisionTaskRecovery {
            public array $enqueued = [];

            protected function enqueue(int $taskId): void
            {
                $this->enqueued[] = $taskId;
            }
        };
    }

    private function seedTask(int $id, string $status, int $retryCount, string $updatedAt): void
    {
        Capsule::table('provision_tasks')->insert([
            'id'            => $id,
            'order_id'      => 10,
            'order_item_id' => 20,
            'product_type'  => 'server',
            'provider'      => 'fake',
            'region_id'     => 1,
            'action'        => 'create',
            'status'        => $status,
            'retry_count'   => $retryCount,
            'next_retry_at' => $updatedAt,
            'created_at'    => $updatedAt,
            'updated_at'    => $updatedAt,
        ]);
    }

    private function minutesAgo(int $minutes): string
    {
        return date('Y-m-d H:i:s', time() - $minutes * 60);
    }
}
