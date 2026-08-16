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

        // 预取费率映射（meter => [region_id => rate]）与资源（含 user），避免循环内 N+1 查询
        $rateMap = [];
        foreach (Capsule::table('usage_rates')->get() as $rate) {
            $rateMap[$rate->meter][$rate->region_id ?? 'null'] = $rate;
        }
        $resourceIds = array_keys($events->toArray());
        $resources = Resource::with('user')->whereIn('id', $resourceIds)->get()->keyBy('id');

        foreach ($events as $resourceId => $resourceEvents) {
            Capsule::transaction(function () use ($resourceId, $resourceEvents, $resources, $yesterdayStart, $yesterdayEnd) {
                $resource = $resources[$resourceId] ?? null;
                if (!$resource) return;

                // 按费率币种分桶累计（usage_invoice_items 的 currency 与费率一致）
                $totals = [];
                foreach ($resourceEvents as $event) {
                    $rate = $rateMap[$event->meter][$resource->region_id ?? 'null']
                        ?? $rateMap[$event->meter]['null']
                        ?? null;

                    $unitPrice = $rate ? $rate->unit_price : '0';
                    $amount = bcmul($event->quantity, $unitPrice, 8);
                    $currency = $rate ? $rate->currency : 'USD';

                    Capsule::table('usage_invoice_items')->insert([
                        'resource_id'  => $resource->id,
                        'meter'        => $event->meter,
                        'quantity'     => $event->quantity,
                        'amount'       => $amount,
                        'currency'     => $currency,
                        'period_start' => $event->period_start,
                        'period_end'   => $event->period_end,
                        'created_at'   => date('Y-m-d H:i:s'),
                    ]);

                    $totals[$currency] = bcadd($totals[$currency] ?? '0', $amount, 8);
                }

                // 按费率币种查对应余额账户，账户缺失时回退 USD
                $deductions = [];
                $sufficient = true;
                $totalOwed  = '0';
                foreach ($totals as $currency => $amount) {
                    $totalOwed = bcadd($totalOwed, $amount, 8);
                    $balance = UserBalance::where('user_id', $resource->user_id)
                        ->where('currency', $currency)
                        ->lockForUpdate()
                        ->first();
                    if (!$balance && $currency !== 'USD') {
                        $balance = UserBalance::where('user_id', $resource->user_id)
                            ->where('currency', 'USD')
                            ->lockForUpdate()
                            ->first();
                    }
                    if (!$balance || bccomp($balance->balance, $amount, 4) < 0) {
                        $sufficient = false;
                        break;
                    }
                    $deductions[] = ['balance' => $balance, 'currency' => $currency, 'amount' => $amount];
                }

                if ($sufficient) {
                    foreach ($deductions as $d) {
                        $d['balance']->decrement('balance', $d['amount']);
                        UserBalanceLog::create([
                            'user_id'  => $resource->user_id,
                            'type'     => 'usage_deduction',
                            'amount'   => $d['amount'],
                            'currency' => $d['currency'],
                            'remark'   => "Usage billing for resource {$resource->id}",
                        ]);
                    }

                    // 扣费成功才标 billed
                    Capsule::table('usage_events')
                        ->where('resource_id', $resourceId)
                        ->where('status', 'open')
                        ->where('period_start', '>=', $yesterdayStart)
                        ->where('period_start', '<', $yesterdayEnd)
                        ->update(['status' => 'billed']);
                } else {
                    // 余额不足：挂起资源但事件保留 open，充值后可重跑扣回
                    if ($resource->status === 'active') {
                        $resource->update(['status' => 'suspended']);
                        if ($resource->user_id) {
                            (new \App\Notification\Service\NotificationDispatcher())->dispatch(
                                $resource->user_id, 'resource_suspended',
                                ['resource_id' => $resource->id, 'reason' => 'Insufficient balance for usage billing, debt ' . $totalOwed],
                                ['email', 'in_app']
                            );
                        }
                    }
                }
            });
        }
    }
}
