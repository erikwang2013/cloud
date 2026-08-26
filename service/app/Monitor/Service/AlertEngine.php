<?php
namespace App\Monitor\Service;

use App\Monitor\Model\Alert;
use App\User\Model\User;
use App\Notification\Service\NotificationDispatcher;
use App\Provisioning\Event\ProvisionFailed;

class AlertEngine
{
    private array $alertRules = [
        'server_down' => [
            'severity' => 'critical',
            'notify'   => ['in_app', 'email', 'sms'],
        ],
        'cpu_high' => [
            'severity'  => 'warning',
            'threshold' => ['cpu_percent' => 90, 'duration_minutes' => 10],
            'notify'    => ['in_app', 'email'],
        ],
        'disk_high' => [
            'severity'  => 'warning',
            'threshold' => ['disk_percent' => 90, 'duration_minutes' => 5],
            'notify'    => ['in_app', 'email'],
        ],
        'ssl_expiring' => [
            'severity'  => 'warning',
            'threshold' => ['days_left' => 30],
            'notify'    => ['in_app', 'email'],
        ],
        'domain_expiring' => [
            'severity'  => 'warning',
            'threshold' => ['days_left' => 30],
            'notify'    => ['in_app', 'email'],
        ],
        'provision_failed' => [
            'severity' => 'critical',
            'notify'   => ['in_app', 'email', 'sms'],
        ],
    ];

    public function trigger(string $ruleCode, $resource, array $context = []): void
    {
        $rule = $this->alertRules[$ruleCode] ?? null;
        if (!$rule) return;

        Alert::create([
            'rule_code'   => $ruleCode,
            'severity'    => $rule['severity'],
            'resource_id' => $resource->id ?? null,
            'user_id'     => $resource->user_id ?? 0,
            'context'     => $context, // Alert 模型 cast 为 array，会自行 json_encode，避免双编码
            'status'      => 'triggered',
        ]);

        $dispatcher = new NotificationDispatcher();
        $dispatcher->dispatch(
            $resource->user_id ?? 0,
            'alert_' . $ruleCode,
            array_merge($context, ['resource_type' => $resource->type ?? 'unknown']),
            $rule['notify']
        );

        if (in_array($rule['severity'], ['critical', 'major'])) {
            $this->notifyOnCall($ruleCode, $resource, $context);
        }
    }

    private function notifyOnCall(string $ruleCode, $resource, array $context): void
    {
        $oncallStaff = User::where('role', 'admin')
            ->where('status', 'active')
            ->get();

        $dispatcher = new NotificationDispatcher();
        foreach ($oncallStaff as $staff) {
            $dispatcher->dispatch($staff->id, 'alert_oncall', array_merge($context, [
                'rule_code'   => $ruleCode,
                'resource_id' => $resource->id ?? 'N/A',
            ]), ['sms']);
        }
    }

    public function onProvisionFailed(ProvisionFailed $event): void
    {
        $this->trigger('provision_failed', $event->task, [
            'task_id'    => $event->task->id,
            'order_id'   => $event->task->order_id,
            'last_error' => $event->task->last_error,
        ]);
    }
}
