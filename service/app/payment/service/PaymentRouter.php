<?php
namespace App\payment\service;

use App\payment\model\PaymentChannel;
use Common\money\Money;

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
                // ponytail: 展示=实收（total_amount = amount）。createPaymentIntent 只按 order->total
                // 扣款，fee 从未实收；若业务确认要收通道费，需在 StripeChannel 增加实际收取逻辑
                'total_amount' => Money::bcround($context['amount'], 4),
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
