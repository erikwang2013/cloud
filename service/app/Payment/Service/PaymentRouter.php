<?php
namespace App\Payment\Service;

use App\Payment\Model\PaymentChannel;

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
                'total_amount' => bcadd($context['amount'], $fee, 4),
            ];
        }

        return $result;
    }

    private function calculateFee(string $amount, array $feeConfig): string
    {
        $fixed = $feeConfig['fixed'] ?? '0';
        $rate  = $feeConfig['rate'] ?? '0';
        $fee   = bcadd(bcmul($amount, $rate, 8), $fixed, 4);
        return max($fee, '0');
    }
}
