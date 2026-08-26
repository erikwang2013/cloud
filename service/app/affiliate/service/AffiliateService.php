<?php
namespace App\affiliate\service;

use App\affiliate\model\AffiliateLink;
use App\affiliate\model\AffiliateEarning;
use App\affiliate\model\AffiliatePayout;
use App\user\model\User;
use App\user\model\UserBalance;
use App\user\model\UserBalanceLog;
use Common\money\Money;
use Illuminate\Database\Capsule\Manager as Capsule;

class AffiliateService
{
    public function generateLink(int $userId, ?string $source = null): AffiliateLink
    {
        $existing = AffiliateLink::where('user_id', $userId)->first();
        if ($existing) return $existing;

        return AffiliateLink::create([
            'user_id' => $userId,
            'code'    => AffiliateLink::generateCode(),
            'source'  => $source,
        ]);
    }

    public function attributeOrder(int $orderId, int $referredUserId): void
    {
        $user = User::find($referredUserId);
        if (!$user || !$user->affiliate_code) return;

        $affiliate = AffiliateLink::where('code', $user->affiliate_code)->first();
        if (!$affiliate) return;

        $plan = Capsule::table('affiliate_plans')->orderBy('tier')->first();
        $rate = $plan ? (string) $plan->commission_rate : '10';

        $order = \App\order\model\Order::find($orderId);
        if (!$order) return;

        $amount = self::earningAmount((string) $order->total, $rate);

        AffiliateEarning::create([
            'affiliate_id' => $affiliate->user_id,
            'order_id'     => $orderId,
            'user_id'      => $referredUserId,
            'rate'         => $rate,
            'amount'       => $amount,
            'currency'     => $order->currency,
            'status'       => 'pending',
        ]);
    }

    // D4：佣金 = total × (rate%/100)，字符串 bcmath（率可能是 12.55 等非整百分比），写 DECIMAL 前 bcround 到 4 位
    public static function earningAmount(string $total, string $ratePercent): string
    {
        return Money::bcround(bcmul($total, bcdiv($ratePercent, '100', 8), 8), 4);
    }

    public function requestPayout(int $userId): AffiliatePayout
    {
        return Capsule::transaction(function () use ($userId) {
            // 行锁串行化同一用户的并发提现请求，避免重复创建 pending 提现
            User::where('id', $userId)->lockForUpdate()->first();

            $existing = AffiliatePayout::where('affiliate_id', $userId)
                ->where('status', 'pending')
                ->first();
            if ($existing) {
                return $existing;
            }

            // D4：SUM 用 selectRaw 直接取 DECIMAL 字符串，Eloquent sum() 会经浮点丢失精度
            $totalEarned = (string) Capsule::table('affiliate_earnings')
                ->where('affiliate_id', $userId)
                ->where('status', 'approved')
                ->value(Capsule::raw('COALESCE(SUM(amount), 0)'));

            $totalPaid = (string) Capsule::table('affiliate_payouts')
                ->where('affiliate_id', $userId)
                ->where('status', 'paid')
                ->value(Capsule::raw('COALESCE(SUM(amount), 0)'));

            $available = bcsub($totalEarned, $totalPaid, 4);
            if (bccomp($available, '50', 4) < 0) {
                throw new \RuntimeException('Minimum payout is 50.00 USD');
            }

            return AffiliatePayout::create([
                'affiliate_id' => $userId,
                'amount'       => $available,
                'status'       => 'pending',
            ]);
        });
    }

    public function approvePayout(int $payoutId): void
    {
        $payout = AffiliatePayout::findOrFail($payoutId);

        Capsule::transaction(function () use ($payout) {
            // 行锁 + 状态守卫：仅允许 pending 状态的提现审批，防止重复审批双重入账
            $locked = AffiliatePayout::where('id', $payout->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                throw new \RuntimeException("Payout #{$payoutId} is not pending, cannot approve");
            }

            $payout->update(['status' => 'approved', 'paid_at' => date('Y-m-d H:i:s')]);

            $balance = UserBalance::firstOrCreate(
                ['user_id' => $payout->affiliate_id, 'currency' => 'USD'],
                ['balance' => 0, 'frozen_balance' => 0]
            );
            $balance->increment('balance', $payout->amount);

            UserBalanceLog::create([
                'user_id'  => $payout->affiliate_id,
                'type'     => 'affiliate_payout',
                'amount'   => $payout->amount,
                'currency' => 'USD',
                'remark'   => "Affiliate payout #{$payout->id}",
            ]);

            AffiliateEarning::where('affiliate_id', $payout->affiliate_id)
                ->where('status', 'approved')
                ->update(['status' => 'paid']);
        });

        // 事务提交后通知推广人提现已处理（通知非关键，失败不影响提现结果）
        try {
            (new \App\notification\service\NotificationDispatcher())->dispatch(
                $payout->affiliate_id, 'affiliate_payout_processed',
                ['amount' => (string) $payout->amount, 'currency' => 'USD'],
                ['email', 'in_app']
            );
        } catch (\Throwable) {
            // 忽略通知异常
        }
    }
}
