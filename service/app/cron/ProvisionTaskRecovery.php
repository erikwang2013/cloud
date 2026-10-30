<?php
namespace App\cron;

use App\order\model\Order;
use App\order\model\OrderTimeline;
use App\provisioning\model\ProvisionTask;
use Webman\RedisQueue\Client;

/**
 * 回收崩溃/重启后卡在 processing 的交付任务。
 * claim 已把任务置 processing，进程失联后没有其它路径推进；超时任务按重试策略回 pending 并重新入队。
 */
class ProvisionTaskRecovery
{
    // 超过该时长未更新视为执行进程已失联（需大于正常 provider 创建耗时）
    public const TIMEOUT_MINUTES = 15;
    private const BATCH = 100;
    private const MAX_RETRIES = 7;

    public function run(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::TIMEOUT_MINUTES * 60);

        $tasks = ProvisionTask::where('status', 'processing')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('updated_at')
            ->take(self::BATCH)
            ->get();

        foreach ($tasks as $task) {
            $this->recover($task, $cutoff);
        }

        $this->completeFinishedOrders();
    }

    /**
     * 补偿 checkOrderComplete 失败：所有 create 任务已完成但订单仍 provisioning。
     */
    private function completeFinishedOrders(): void
    {
        $orders = Order::where('status', 'provisioning')
            ->with('items.tasks')
            ->orderBy('id')
            ->take(self::BATCH)
            ->get();

        foreach ($orders as $order) {
            if ($order->items->isEmpty()) {
                continue;
            }
            $allDone = $order->items->every(function ($item) {
                return $item->tasks->firstWhere('action', 'create')?->status === 'completed';
            });
            if (!$allDone) {
                continue;
            }

            // 条件更新：多 cron 实例并发时只有一方写 timeline
            $affected = Order::where('id', $order->id)
                ->where('status', 'provisioning')
                ->update(['status' => 'completed']);
            if ($affected === 0) {
                continue;
            }

            OrderTimeline::create([
                'order_id' => $order->id,
                'status'   => 'completed',
                'operator' => 'system',
                'remark'   => 'All resources provisioned (recovery)',
            ]);
        }
    }

    private function recover(ProvisionTask $task, string $cutoff): void
    {
        $retries = (int) $task->retry_count + 1;

        if ($retries >= self::MAX_RETRIES) {
            $values = [
                'status'      => 'failed',
                'retry_count' => $retries,
                'last_error'  => 'Processing timeout: worker lost before completion',
            ];
        } else {
            $values = [
                'status'        => 'pending',
                'retry_count'   => $retries,
                'last_error'    => 'Processing timeout: requeued by recovery',
                'next_retry_at' => date('Y-m-d H:i:s'),
            ];
        }

        // 条件更新含 status/retry_count/updated_at：多 cron 实例并发时只有一方能回收
        $affected = ProvisionTask::where('id', $task->id)
            ->where('status', 'processing')
            ->where('retry_count', $task->retry_count)
            ->where('updated_at', '<', $cutoff)
            ->update($values);

        if ($affected === 0) {
            return;
        }

        if ($values['status'] === 'failed') {
            \support\Log::warning("Provision recovery marked task failed after timeout: task={$task->id}");
            return;
        }

        try {
            $this->enqueue((int) $task->id);
        } catch (\Throwable $e) {
            // 入队失败由下一轮 recovery 兜底（任务已回 pending，仍会被重新选中）
            \support\Log::warning("Provision recovery enqueue failed: task={$task->id}: {$e->getMessage()}");
        }
    }

    protected function enqueue(int $taskId): void
    {
        Client::send('provisioning', ['task_id' => $taskId]);
    }
}
