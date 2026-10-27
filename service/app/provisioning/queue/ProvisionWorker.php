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
use Webman\RedisQueue\Consumer;

class ProvisionWorker implements Consumer
{
    public string $queue = 'provisioning';

    public function consume($data)
    {
        $tasks = ProvisionTask::where('status', 'pending')
            ->where('next_retry_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('created_at')
            ->take(50)
            ->get();

        $factory = new ProviderFactory();

        foreach ($tasks as $task) {
            try {
                $order = Order::find($task->order_id);
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

                $provider = $factory->create($task);
                $result = $provider->create($task);

                if ($result->status === 'success') {
                    $resource->update(['status' => 'active', 'provisioned_at' => date('Y-m-d H:i:s')]);
                    $task->update(['status' => 'completed']);

                    WebhookDispatcher::dispatch(WebhookDispatcher::EVENT_RESOURCE_PROVISIONED, [
                        'resource_id'   => $resource->id,
                        'type'          => $resource->type,
                        'order_item_id' => $task->order_item_id,
                    ]);

                    WebSocketServer::send($order->user_id, 'resource.provisioned', [
                        'resource_id' => $resource->id,
                        'type'        => $resource->type,
                        'ip_address'  => $result->data['ip_address'] ?? null,
                    ]);

                    $this->checkOrderComplete($task->order_id);

                } elseif ($result->status === 'retryable') {
                    $retries = $task->retry_count + 1;
                    // Delays in minutes: 1, 5, 15, 60 (1h), 360 (6h), 1440 (24h)
                    $delays  = [1, 5, 15, 60, 360, 1440];
                    $delay   = $delays[$retries] ?? $delays[5];

                    $task->update([
                        'status'        => 'pending',
                        'retry_count'   => $retries,
                        'last_error'    => $result->errorMessage,
                        'next_retry_at' => date('Y-m-d H:i:s', time() + $delay * 60),
                    ]);

                    if ($retries >= 6) {
                        $task->update(['status' => 'failed']);
                        $this->alertFailure($task);
                    }
                } else {
                    $task->update([
                        'status'     => 'failed',
                        'last_error' => $result->errorMessage,
                    ]);
                    $this->alertFailure($task);
                }
            } catch (\Exception $e) {
                $task->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
                $this->alertFailure($task);
            }
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
