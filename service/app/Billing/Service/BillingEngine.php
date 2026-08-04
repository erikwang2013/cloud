<?php
namespace App\Billing\Service;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\User\Model\UserBalance;
use App\User\Model\UserBalanceLog;
use App\Provisioning\Model\Resource;

class BillingEngine
{
    public function runDaily(): void
    {
        $yesterdayStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $yesterdayEnd   = date('Y-m-d 00:00:00');

        $events = Capsule::table('usage_events')
            ->where('status', 'open')
            ->where('period_start', '>=', $yesterdayStart)
            ->where('period_start', '<', $yesterdayEnd)
            ->get()
            ->groupBy('resource_id');

        foreach ($events as $resourceId => $resourceEvents) {
            $resource = Resource::with('user')->find($resourceId);
            if (!$resource) continue;

            $totalAmount = '0';
            foreach ($resourceEvents as $event) {
                $rate = Capsule::table('usage_rates')
                    ->where('meter', $event->meter)
                    ->where(function ($q) use ($resource) {
                        $q->where('region_id', $resource->region_id)->orWhereNull('region_id');
                    })
                    ->orderBy('region_id', 'desc')
                    ->first();

                $unitPrice = $rate ? $rate->unit_price : '0';
                $amount = bcmul($event->quantity, $unitPrice, 8);

                Capsule::table('usage_invoice_items')->insert([
                    'resource_id'  => $resource->id,
                    'meter'        => $event->meter,
                    'quantity'     => $event->quantity,
                    'amount'       => $amount,
                    'currency'     => $rate ? $rate->currency : 'USD',
                    'period_start' => $event->period_start,
                    'period_end'   => $event->period_end,
                    'created_at'   => date('Y-m-d H:i:s'),
                ]);

                $totalAmount = bcadd($totalAmount, $amount, 8);
            }

            $balance = UserBalance::where('user_id', $resource->user_id)
                ->where('currency', 'USD')
                ->lockForUpdate()
                ->first();

            if ($balance && bccomp($balance->balance, $totalAmount, 4) >= 0) {
                $balance->decrement('balance', $totalAmount);
                UserBalanceLog::create([
                    'user_id'  => $resource->user_id,
                    'type'     => 'usage_deduction',
                    'amount'   => $totalAmount,
                    'currency' => 'USD',
                    'remark'   => "Usage billing for resource {$resource->id}",
                ]);
            } else {
                if ($resource->status === 'active') {
                    $resource->update(['status' => 'suspended']);
                    $user = $resource->user;
                    if ($user) {
                        (new \App\Notification\Service\NotificationDispatcher())->dispatch(
                            $user, 'resource_suspended',
                            ['resource_id' => $resource->id, 'reason' => 'Insufficient balance for usage billing'],
                            ['email', 'in_app']
                        );
                    }
                }
            }

            Capsule::table('usage_events')
                ->where('resource_id', $resourceId)
                ->where('status', 'open')
                ->update(['status' => 'billed']);
        }
    }
}
