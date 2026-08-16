<?php
namespace App\Billing\Cron;

use App\Provisioning\Model\Resource;
use App\User\Model\UserBalance;
use Illuminate\Database\Capsule\Manager as Capsule;

class SuspendCheck
{
    /**
     * 解挂判定：资源被挂起是因为某币种余额不足（BillingEngine 按费率币种分桶扣款）。
     * 仅当每个有未结欠费的币种余额都足够（非 USD 账户缺失时回退 USD），才允许解挂。
     * 金额比较一律 bccomp，禁止 float。
     *
     * @param array $balances  currency => balance(string)
     * @param array $owedByCurrency currency => 未结欠费(string)
     */
    public function canUnsuspend(array $balances, array $owedByCurrency): bool
    {
        foreach ($owedByCurrency as $currency => $owed) {
            $balance = $balances[$currency] ?? ($currency !== 'USD' ? ($balances['USD'] ?? null) : null);
            if ($balance === null || bccomp((string) $balance, (string) $owed, 4) < 0) {
                return false;
            }
        }
        return true;
    }

    public function run(): void
    {
        echo date('Y-m-d H:i:s') . " SuspendCheck: Checking suspended resources...\n";

        $suspended = Resource::where('status', 'suspended')->get();

        foreach ($suspended as $resource) {
            $owed = [];
            $rows = Capsule::table('usage_invoice_items as i')
                ->join('usage_events as e', function ($join) {
                    $join->on('i.resource_id', '=', 'e.resource_id')
                        ->on('i.meter', '=', 'e.meter')
                        ->on('i.period_start', '=', 'e.period_start');
                })
                ->where('i.resource_id', $resource->id)
                ->where('e.status', 'open')
                ->get(['i.currency', 'i.amount']);
            foreach ($rows as $row) {
                $owed[$row->currency] = bcadd($owed[$row->currency] ?? '0', $row->amount, 4);
            }

            $balances = UserBalance::where('user_id', $resource->user_id)
                ->get()
                ->pluck('balance', 'currency')
                ->toArray();

            if ($this->canUnsuspend($balances, $owed)) {
                $resource->update(['status' => 'active']);
                echo "  Resource {$resource->id}: unsuspended (owed: " . json_encode($owed) . ", balances: " . json_encode($balances) . ")\n";

                $user = $resource->user;
                if ($user) {
                    (new \App\Notification\Service\NotificationDispatcher())->dispatch(
                        $user->id, 'resource_reactivated',
                        ['resource_id' => $resource->id],
                        ['email', 'in_app']
                    );
                }
            }
        }

        echo date('Y-m-d H:i:s') . " SuspendCheck: Done.\n";
    }
}
