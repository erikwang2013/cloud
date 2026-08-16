<?php
namespace App\Cron;

use App\Payment\Model\PaymentChannel;
use App\Payment\Model\PaymentTransaction;
use App\Payment\Service\Channels\StripeChannel;
use Common\Money\Money;
use Illuminate\Database\Capsule\Manager as Capsule;

class PaymentReconcile
{
    public function run(?string $date = null): void
    {
        $date = $date ?: date('Y-m-d');
        $this->assertValidDate($date);

        echo date('Y-m-d H:i:s') . " PaymentReconcile: reconciling {$date}...\n";

        $channels = PaymentChannel::where('status', 'active')->get();
        $table    = Capsule::table('payment_reconcile');
        $external = 0;

        foreach ($channels as $channel) {
            $systemByCurrency = self::systemTotals($channel->id, $date);
            $row = ['channel_total' => '0', 'system_total' => '0', 'diff' => '0', 'status' => 'unverified'];
            $detail = '';

            if ($channel->code === 'stripe') {
                $external++;
                try {
                    $report = (new StripeChannel($channel))->fetchReport($date);
                    $result = self::compare($report['by_currency'], $systemByCurrency);
                    $row = [
                        'channel_total' => $result['channel_total'],
                        'system_total'  => $result['system_total'],
                        'diff'          => $result['diff'],
                        'status'        => $result['status'],
                    ];
                    $detail = " (stripe intents: {$report['count']})";
                } catch (\Throwable $e) {
                    $detail = ' - 拉取通道报表失败，置 unverified: ' . $e->getMessage();
                }
            } else {
                $detail = " - 通道 {$channel->code} 无报表拉取实现，置 unverified";
            }

            if ($row['status'] === 'unverified') {
                // 未拉取到报表：记录本地汇总但显式 unverified，禁止伪装成已核对
                $localTotal = self::sumTotals($systemByCurrency);
                $row['channel_total'] = $localTotal;
                $row['system_total']  = $localTotal;
            }

            // upsert：依赖 uniq_reconcile_channel_date 唯一索引做并发兜底（ON DUPLICATE KEY UPDATE）
            $table->upsert(
                ['channel_id' => $channel->id, 'date' => $date] + $row,
                ['channel_id', 'date']
            );

            echo "  Channel {$channel->code}: {$row['status']} channel_total={$row['channel_total']} system_total={$row['system_total']} diff={$row['diff']}{$detail}\n";
        }

        if ($external === 0) {
            echo "  WARNING: 所有通道均无报表拉取实现，无法进行真实对账\n";
        }

        echo date('Y-m-d H:i:s') . " PaymentReconcile: Done.\n";
    }

    /**
     * 按币种逐项比对通道报表与本地交易，任一币种不一致即 mismatch。
     * 两侧先按币种最小单位精度 half-up 舍入再比（与 Webhook 校验同规则），
     * 避免本地 3-4 位小数金额对 Stripe 分精度恒误报 mismatch。
     */
    public static function compare(array $channelByCurrency, array $systemByCurrency): array
    {
        $currencies = array_unique(array_merge(array_keys($channelByCurrency), array_keys($systemByCurrency)));

        $channelTotal = '0';
        $systemTotal  = '0';
        $mismatch     = false;

        foreach ($currencies as $currency) {
            $chRaw = $channelByCurrency[$currency] ?? '0';
            $syRaw = $systemByCurrency[$currency] ?? '0';
            $channelTotal = bcadd($channelTotal, $chRaw, 4);
            $systemTotal  = bcadd($systemTotal, $syRaw, 4);
            if (self::roundUnit($chRaw, $currency) !== self::roundUnit($syRaw, $currency)) {
                $mismatch = true;
            }
        }

        // verified 即最小单位精度全部一致，子分残余不计入 diff
        $diff = $mismatch ? bcsub($channelTotal, $systemTotal, 4) : '0';

        return [
            'channel_total' => $channelTotal,
            'system_total'  => $systemTotal,
            'diff'          => $diff,
            'status'        => $mismatch ? 'mismatch' : 'verified',
        ];
    }

    private static function roundUnit(string $value, string $currency): string
    {
        $scale = StripeChannel::isZeroDecimal($currency) ? 0 : 2;
        return self::roundToScale($value, $scale);
    }

    private static function roundToScale(string $value, int $scale): string
    {
        // 统一走 Common\Money\Money::bcround（D4 唯一金额舍入助手）
        return Money::bcround($value, $scale);
    }

    private static function systemTotals(int $channelId, string $date): array
    {
        return PaymentTransaction::where('channel_id', $channelId)
            ->whereDate('created_at', $date)
            ->where('status', 'success')
            ->selectRaw('currency, SUM(amount) AS total')
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->map(fn ($v) => (string) $v)
            ->all();
    }

    private static function sumTotals(array $byCurrency): string
    {
        return array_reduce($byCurrency, fn ($carry, $v) => bcadd($carry, $v, 4), '0');
    }

    private static function assertValidDate(string $date): void
    {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("Invalid date: {$date}, expected Y-m-d");
        }
    }
}
