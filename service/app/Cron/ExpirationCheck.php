<?php
namespace App\Cron;

use App\WebSocket\WebSocketServer;

class ExpirationCheck
{
    public function run(): void
    {
        echo date('Y-m-d H:i:s') . " ExpirationCheck: Scanning expiring resources and domains...\n";

        $now = date('Y-m-d H:i:s');
        $warnDays = [30, 14, 7, 3, 1];

        // Check resources expiring soon
        foreach ($warnDays as $days) {
            $targetDate = date('Y-m-d', strtotime("+{$days} days"));

            $resources = \App\Provisioning\Model\Resource::where('status', 'active')
                ->whereDate('expired_at', $targetDate)
                ->with('user')
                ->get();

            foreach ($resources as $resource) {
                if ($resource->user_id) {
                    (new \App\Notification\Service\NotificationDispatcher())->dispatch($resource->user_id, 'resource_expiring', [
                        'resource_id'   => $resource->id,
                        'resource_type' => $resource->type,
                        'expired_at'    => $resource->expired_at,
                        'days_left'     => $days,
                    ], ['email', 'in_app']);

                    WebSocketServer::send($resource->user_id, 'resource.expiring', [
                        'resource_id' => $resource->id,
                        'expired_at'  => $resource->expired_at,
                        'days_left'   => $days,
                    ]);
                }

                // 进入 7 天窗口时向供应商发一次 webhook（warnDays 30→1 降序，days=7 仅命中一次）
                if ($days === 7) {
                    \Common\Webhook\WebhookDispatcher::dispatch(
                        \Common\Webhook\WebhookDispatcher::EVENT_RESOURCE_EXPIRING,
                        [
                            'resource_id' => $resource->id,
                            'type'        => $resource->type,
                            'expired_at'  => $resource->expired_at,
                            'days_left'   => $days,
                        ]
                    );
                }
            }
        }

        // Check domain renewals
        $domains = \App\Domain\Model\DnsZone::whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', date('Y-m-d', strtotime('+30 days')))
            ->with('user')
            ->get();

        foreach ($domains as $domain) {
            $daysLeft = (int) ceil((strtotime($domain->expires_at) - time()) / 86400);
            if ($daysLeft > 0 && in_array($daysLeft, $warnDays) && $domain->user) {
                (new \App\Notification\Service\NotificationDispatcher())->dispatch($domain->user_id, 'domain_expiring', [
                    'domain'    => $domain->domain_name,
                    'expires_at'=> $domain->expires_at,
                    'days_left' => $daysLeft,
                ], ['email', 'in_app']);
            }
        }

        echo date('Y-m-d H:i:s') . " ExpirationCheck: Done.\n";
    }
}
