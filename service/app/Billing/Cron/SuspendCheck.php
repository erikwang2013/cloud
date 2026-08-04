<?php
namespace App\Billing\Cron;

use App\Provisioning\Model\Resource;
use App\User\Model\UserBalance;

class SuspendCheck
{
    public function run(): void
    {
        echo date('Y-m-d H:i:s') . " SuspendCheck: Checking suspended resources...\n";

        $suspended = Resource::where('status', 'suspended')->get();

        foreach ($suspended as $resource) {
            $balance = UserBalance::where('user_id', $resource->user_id)
                ->where('currency', 'USD')
                ->first();

            if ($balance && $balance->balance > 1.00) {
                $resource->update(['status' => 'active']);
                echo "  Resource {$resource->id}: unsuspended (balance: {$balance->balance})\n";

                $user = $resource->user;
                if ($user) {
                    \App\Notification\Service\NotificationDispatcher::send(
                        $user, 'resource_reactivated',
                        ['resource_id' => $resource->id],
                        ['email', 'in_app']
                    );
                }
            }
        }

        echo date('Y-m-d H:i:s') . " SuspendCheck: Done.\n";
    }
}
