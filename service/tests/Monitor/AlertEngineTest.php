<?php

namespace Tests\Monitor;

use App\Monitor\Model\Alert;
use App\Monitor\Service\AlertEngine;
use App\Provisioning\Event\ProvisionFailed;
use App\Provisioning\Model\ProvisionTask;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class AlertEngineTest extends TestCase
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

        $schema = $capsule->schema();
        $schema->create('alerts', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->string('rule_code');
            $t->string('severity');
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->unsignedBigInteger('user_id')->default(0);
            $t->text('context')->nullable();
            $t->string('status')->default('triggered');
            $t->timestamps();
        });
        $schema->create('users', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->string('role')->default('user');
            $t->string('status')->default('active');
            $t->string('email')->nullable();
            $t->softDeletes();
        });
    }

    public function testUnknownRuleIsNoOp(): void
    {
        $engine = new AlertEngine();
        $engine->trigger('no_such_rule', (object) ['id' => 1, 'user_id' => 2]);

        $this->assertSame(0, Alert::count());
    }

    public function testTriggerCreatesAlertWithSeverityAndContext(): void
    {
        $engine = new AlertEngine();
        $engine->trigger('cpu_high', (object) ['id' => 42, 'user_id' => 7, 'type' => 'server'], [
            'cpu_percent' => 95,
        ]);

        $alert = Alert::first();
        $this->assertNotNull($alert);
        $this->assertSame('cpu_high', $alert->rule_code);
        $this->assertSame('warning', $alert->severity);
        $this->assertSame(42, $alert->resource_id);
        $this->assertSame(7, $alert->user_id);
        $this->assertSame('triggered', $alert->status);
        // AlertEngine 传数组 + 模型 array cast 编码一次，读回为数组
        $this->assertSame(['cpu_percent' => 95], $alert->context);
    }

    public function testResourceWithoutIdOrUserId(): void
    {
        // 资源对象缺 id/user_id（如事件携带残缺对象）时不得抛异常
        $engine = new AlertEngine();
        $engine->trigger('cpu_high', (object) ['type' => 'server'], ['cpu_percent' => 91]);

        $alert = Alert::first();
        $this->assertNotNull($alert);
        $this->assertNull($alert->resource_id);
        $this->assertSame(0, $alert->user_id);
    }

    public function testCriticalRuleRunsOncallQueryWithoutActiveAdmins(): void
    {
        // users 表为空：notifyOnCall 查无 admin，不应崩溃，alert 照常落库
        $engine = new AlertEngine();
        $engine->trigger('server_down', (object) ['id' => 9, 'user_id' => 3, 'type' => 'server'], [
            'consecutive_checks' => 5,
        ]);

        $this->assertSame(1, Alert::count());
        $this->assertSame('critical', Alert::first()->severity);
    }

    public function testOnProvisionFailedTriggersProvisionAlert(): void
    {
        $task = new ProvisionTask();
        $task->forceFill([
            'id'         => 100,
            'order_id'   => 555,
            'user_id'    => 0,
            'last_error' => 'proxmox timeout',
            'type'       => 'server',
        ]);

        (new AlertEngine())->onProvisionFailed(new ProvisionFailed($task));

        $alert = Alert::where('rule_code', 'provision_failed')->first();
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame(100, $alert->resource_id);
        $this->assertSame(['task_id' => 100, 'order_id' => 555, 'last_error' => 'proxmox timeout'], $alert->context);
    }

    public function testAlertModelFillableAndCasts(): void
    {
        $fillable = (new Alert())->getFillable();
        foreach (['rule_code', 'severity', 'resource_id', 'user_id', 'context', 'status'] as $field) {
            $this->assertContains($field, $fillable);
        }

        $alert = new Alert();
        $alert->context = ['a' => 1];
        $this->assertIsArray($alert->context);
        $this->assertTrue($alert->resource() instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo);
    }
}
