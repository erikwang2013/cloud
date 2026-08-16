<?php
namespace App\Payment\Service;

use App\Payment\Model\PaymentChannel;
use Common\Money\Money;

class PaymentRouter
{
    public function getAvailableChannels(array $context): array
    {
        $channels = PaymentChannel::where('status', 'active')
            ->where('is_visible', true)
            ->get();

        $result = [];
        foreach ($channels as $channel) {
            if ($channel->visible_regions && !in_array($context['region'] ?? 'global', $channel->visible_regions)) {
                continue;
            }
            if ($channel->min_amount && $context['amount'] < $channel->min_amount) continue;
            if ($channel->max_amount && $context['amount'] > $channel->max_amount) continue;
            if (!in_array($context['currency'], $channel->currency_support ?? [])) continue;

            $feeConfig = $channel->fee_config ?? [];
            $fee = $this->calculateFee($context['amount'], $feeConfig);

            $result[] = [
                'channel_id'   => $channel->id,
                'name'         => $channel->name,
                'code'         => $channel->code,
                'amount'       => $context['amount'],
                'fee'          => $fee,
                // 对齐 amount 到 4 位再加 fee（D5：total_amount - amount - fee 精确为 0）
                'total_amount' => bcadd(Money::bcround($context['amount'], 4), $fee, 4),
            ];
        }

        return $result;
    }

    /**
     * 通道费（D4）：先对齐 amount 到 4 位 → 乘率 → HALF_UP 到 4 位。
     * 原实现 bcadd(..., 4) 截断少收 <0.0001/单，改为标准半舍入。
     */
    public function calculateFee(string $amount, array $feeConfig): string
    {
        $fixed = $feeConfig['fixed'] ?? '0';
        $rate  = $feeConfig['rate'] ?? '0';
        $fee = Money::bcround(bcadd(bcmul(Money::bcround($amount, 4), $rate, 8), $fixed, 8), 4);
        return bccomp($fee, '0', 4) < 0 ? '0' : $fee;
    }
}
