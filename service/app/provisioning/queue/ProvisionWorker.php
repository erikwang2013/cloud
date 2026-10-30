<?php
namespace App\provisioning\queue;

use App\provisioning\model\ProvisionTask;
use App\provisioning\service\ProviderFactory;
use App\provisioning\model\Resource;
use App\provisioning\event\ProvisionFailed;
use App\order\model\Order;
use App\order\model\OrderTimeline;
use App\websocket\WebSocketServer;
use Common\webhook\WebhookDispatcher;
use Illuminate\Support\Facades\Event;
use Webman\RedisQueue\Client;
use Webman\RedisQueue\Consumer;

class ProvisionWorker implements Consumer
{
    public string $queue = 'provisioning';

    // 重试间隔（分钟）：1, 5, 15, 60, 360, 1440；retry_count 到 7 即终态 failed
    private const RETRY_DELAYS = [1, 5, 15, 60, 360, 1440];
    private const MAX_RETRIES  = 7;

    public function consume($data)
    {
        // 消息带 task_id：只处理指定任务，延迟重试消息由队列自身调度
        if (is_array($data) && !empty($data['task_id'])) {
            $task = $this->claim((int) $data['task_id'], false);
            if ($task) {
                $this->process($task);
            }
            return;
        }

        // 兼容无 task_id 的旧消息：扫描到期任务，逐条原子抢占避免多消费者重复执行
        $ids = ProvisionTask::where('status', 'pending')
            ->where('next_retry_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('created_at')
            ->take(50)
            ->pluck('id');

        foreach ($ids as $id) {
            $task = $this->claim((int) $id, true);
            if ($task) {
                $this->process($task);
            }
        }
    }

    /**
     * 原子抢占：pending -> processing，affected=0 说明已被其他消费者拿走。
     */
    private function claim(int $taskId, bool $requireDue): ?ProvisionTask
    {
        $query = ProvisionTask::where('id', $taskId)->where('status', 'pending');
        if ($requireDue) {
            $query->where('next_retry_at', '<=', date('Y-m-d H:i:s'));
        }

        if ($query->update(['status' => 'processing']) === 0) {
            return null;
        }

        return ProvisionTask::find($taskId);
    }

    private function process(ProvisionTask $task): void
    {
        try {
            $order = Order::find($task->order_id);
            if (!$order) {
                throw new \RuntimeException("Order {$task->order_id} not found");
            }

            // 重试时复用已创建的 resource，避免每次重试留下孤儿 provisioning 记录
            $resource = $task->resource_id ? Resource::find($task->resource_id) : null;
            if (!$resource) {
                $resource = Resource::create([
                    'order_item_id'  => $task->order_item_id,
                    'user_id'        => $order->user_id,
                    'product_id'     => $task->orderItem->product_id,
                    'type'           => $task->product_type,
                    'provider'       => $task->provider,
                    'region_id'      => $task->region_id,
                    'status'         => 'provisioning',
                    'expired_at'     => $this->calcExpiry($task),
                ]);

                $task->update(['resource_id' => $resource->id]);
            }

            $provider = (new ProviderFactory())->create($task);
            $result = $provider->create($task);

            if ($result->status === 'success') {
                $resource->update(['status' => 'active', 'provisioned_at' => date('Y-m-d H:i:s')]);
                $task->update(['status' => 'completed']);

                // 三个副作用彼此隔离：任一失败不得跳过其余步骤，也不得回滚已完成的交付
                try {
                    WebhookDispatcher::dispatch(WebhookDispatcher::EVENT_RESOURCE_PROVISIONED, [
                        'resource_id'   => $resource->id,
                        'type'          => $resource->type,
                        'order_item_id' => $task->order_item_id,
                    ]);
                } catch (\Throwable $e) {
                    \support\Log::warning("Provision webhook failed: task={$task->id}: {$e->getMessage()}");
                }

                try {
                    WebSocketServer::send($order->user_id, 'resource.provisioned', [
                        'resource_id' => $resource->id,
                        'type'        => $resource->type,
                        'ip_address'  => $result->data['ip_address'] ?? null,
                    ]);
                } catch (\Throwable $e) {
                    \support\Log::warning("Provision ws notify failed: task={$task->id}: {$e->getMessage()}");
                }

                try {
                    $this->checkOrderComplete($task->order_id);
                } catch (\Throwable $e) {
                    \support\Log::warning("Provision order completion check failed: order={$task->order_id}: {$e->getMessage()}");
                }
            } elseif ($result->status === 'retryable') {
                $this->retryOrFail($task, $result->errorMessage);
            } else {
                $task->update([
                    'status'     => 'failed',
                    'last_error' => $result->errorMessage,
                ]);
                $this->alertFailure($task);
            }
        } catch (\Throwable $e) {
            // 异常一律按可重试处理，达到上限才永久 failed（避免一次瞬时故障丢任务）
            $this->retryOrFail($task, $e->getMessage());
        }
    }

    private function retryOrFail(ProvisionTask $task, ?string $error): void
    {
        // 状态机守卫：completed/failed 是终态，成功后的 webhook/WS/checkOrderComplete 异常不得把任务打回 pending
        if (in_array($task->status, ['completed', 'failed'], true)) {
            \support\Log::warning("Provision terminal task side-effect failed: task={$task->id}: {$error}");
            return;
        }

        $retries = (int) $task->retry_count + 1;

        if ($retries >= self::MAX_RETRIES) {
            $task->update([
                'status'      => 'failed',
                'retry_count' => $retries,
                'last_error'  => $error,
            ]);
            $this->alertFailure($task);
            return;
        }

        $delay = self::RETRY_DELAYS[min($retries, count(self::RETRY_DELAYS)) - 1];

        $task->update([
            'status'        => 'pending',
            'retry_count'   => $retries,
            'last_error'    => $error,
            'next_retry_at' => date('Y-m-d H:i:s', time() + $delay * 60),
        ]);

        // 没有新订单时也要重试：延迟重新入队；入队失败由到期扫描兜底
        try {
            Client::send('provisioning', ['task_id' => $task->id], $delay * 60);
        } catch (\Throwable $e) {
            \support\Log::warning("Provision retry enqueue failed: task={$task->id}: {$e->getMessage()}");
        }
    }

    private function checkOrderComplete(int $orderId): void
    {
        $order = Order::with('items.tasks')->find($orderId);
        $allDone = $order->items->every(function ($item) {
            return $item->tasks->firstWhere('action', 'create')?->status === 'completed';
        });

        if ($allDone) {
            $order->update(['status' => 'completed']);
            OrderTimeline::create([
                'order_id' => $orderId,
                'status'   => 'completed',
                'operator' => 'system',
                'remark'   => 'All resources provisioned',
            ]);
        }
    }

    private function calcExpiry(ProvisionTask $task): string
    {
        $params = json_decode($task->params, true);
        $cycle  = $params['cycle'] ?? 'monthly';
        $months = $cycle === 'yearly' ? 12 : ($cycle === 'quarterly' ? 3 : 1);
        return date('Y-m-d H:i:s', strtotime("+{$months} months"));
    }

    private function alertFailure(ProvisionTask $task): void
    {
        if (class_exists(Event::class)) {
            Event::dispatch(new ProvisionFailed($task));
        }
    }
}
