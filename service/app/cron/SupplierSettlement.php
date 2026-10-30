<?php
namespace App\cron;

use App\order\model\Order;
use App\supplier\model\SupplierProduct;
use Common\money\Money;
use support\Log;

class SupplierSettlement
{
    public function run(): void
    {
        echo date('Y-m-d H:i:s') . " SupplierSettlement: Starting weekly settlement...\n";

        // 供应商状态用 active（SupplierService::approve / admin 表单的取值），旧代码写的 approved 永不命中
        $suppliers = \App\supplier\model\Supplier::where('status', 'active')->get();
        // 上一完整周 [上周一, 上周日]：cron 周一 04:17 跑，若用 'monday this week' 周一解析为
        // 当天 → 窗口退化为单天，周二~周日完成的订单永不结算；以 last sunday 锚点回推 6 天，
        // 任意一天补跑窗口都恒定不漂移、不倒挂
        $weekEnd   = date('Y-m-d', strtotime('last sunday'));
        $weekStart = date('Y-m-d', strtotime('-6 days', strtotime($weekEnd)));

        foreach ($suppliers as $supplier) {
            try {
                // 幂等：同一供应商同一结算周期已存在则跳过，避免 cron 重复执行产生重复结算单
                $exists = \App\supplier\model\SupplierSettlement::where('supplier_id', $supplier->id)
                    ->where('period_start', $weekStart)
                    ->where('period_end', $weekEnd)
                    ->exists();
                if ($exists) continue;

                // 只累加该供应商的 order_items 明细（多供应商订单不能按整单 total 结算）
                [$totalSales, $commission, $payable] = self::salesAndCommission((int) $supplier->id, $weekStart, $weekEnd);
                if (bccomp($totalSales, '0', 4) === 0) continue;

                try {
                    $settlement = \App\supplier\model\SupplierSettlement::create([
                        'supplier_id'  => $supplier->id,
                        'period_start' => $weekStart,
                        'period_end'   => $weekEnd,
                        'total_sales'  => $totalSales,
                        'commission'   => $commission,
                        'payable'      => $payable,
                        'status'       => 'pending',
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // 并发 cron 实例撞唯一索引：视为已生成，跳过
                    if (($e->errorInfo[0] ?? '') === '23000') continue;
                    throw $e;
                }

                \Common\webhook\WebhookDispatcher::dispatch(
                    \Common\webhook\WebhookDispatcher::EVENT_SETTLEMENT_CREATED,
                    [
                        'settlement_id' => $settlement->id,
                        'supplier_id'   => $supplier->id,
                        'amount'        => (string) $payable,
                        'period_start'  => $weekStart,
                        'period_end'    => $weekEnd,
                    ]
                );

                echo "  Supplier #{$supplier->id}: Sales={$totalSales}, Payable={$payable}\n";
            } catch (\Throwable $e) {
                echo "  Supplier #{$supplier->id}: ERROR - {$e->getMessage()}\n";
            }
        }

        echo date('Y-m-d H:i:s') . " SupplierSettlement: Done.\n";
    }

    /**
     * 计算某供应商在结算窗口内的销售额/佣金/应付。
     * 归属周期按 order_timeline 的 completed 记录；金额只取该供应商自己的明细行。
     * 佣金比例来自 supplier_products.commission_rate（0.1000 = 10%），逐行 HALF_UP 累计。
     */
    public static function salesAndCommission(int $supplierId, string $weekStart, string $weekEnd): array
    {
        // 仅使用已审批的商品费率；未审批记录不参与结算（缺失时回落默认 10%）
        $rates = SupplierProduct::where('supplier_id', $supplierId)
            ->whereNotNull('approved_at')
            ->pluck('commission_rate', 'product_id')
            ->toArray();

        $totalSales = '0.0000';
        $commission = '0.0000';

        foreach (self::ordersFor($supplierId, $weekStart, $weekEnd) as $order) {
            // 结算统一折算到 USD：exchange_rate = 外币/USD，非 USD 必须除以快照汇率。
            // 禁止跨币种直接相加（settlements 表没有 currency 列）。
            $currency = strtoupper((string) $order->currency);
            $rate     = (string) ($order->exchange_rate ?? '1');
            if ($currency !== 'USD' && (bccomp($rate, '0', 6) <= 0)) {
                Log::warning("Supplier settlement skipped zero exchange_rate: supplier={$supplierId} order={$order->id}");
                continue;
            }

            foreach ($order->items as $item) {
                $raw = (string) $item->total_price;
                $amount = $currency === 'USD'
                    ? Money::bcround($raw, 4)
                    : Money::bcround(bcdiv($raw, $rate, 8), 4);
                $commissionRate = self::normalizeRate((string) ($rates[(string) $item->product_id] ?? '0.1000'));
                $totalSales = bcadd($totalSales, $amount, 4);
                $commission = bcadd($commission, Money::bcround(bcmul($amount, $commissionRate, 8), 4), 4);
            }
        }

        return [$totalSales, $commission, bcsub($totalSales, $commission, 4)];
    }

    /**
     * 窗口内已完成的订单，且 eager load 只保留该供应商的明细行。
     */
    private static function ordersFor(int $supplierId, string $weekStart, string $weekEnd)
    {
        $forSupplier = function ($q) use ($supplierId) {
            $q->whereHas('sku', fn($q) => $q->whereHas('product', fn($q) => $q->where('supplier_id', $supplierId)));
        };

        return Order::whereHas('items', $forSupplier)
            ->whereHas('timeline', function ($q) use ($weekStart, $weekEnd) {
                $q->where('status', 'completed')
                  ->whereBetween('created_at', [$weekStart . ' 00:00:00', $weekEnd . ' 23:59:59']);
            })
            ->where('status', 'completed')
            ->with(['items' => $forSupplier])
            ->get();
    }

    /**
     * 费率防护：仅接受 0 ≤ rate < 1 且最多 4 位小数；越界/脏数据回落默认 10%。
     * 防止旧数据或绕过供应商入口写入的负值/超 100% 费率放大应付金额。
     */
    private static function normalizeRate(string $rate): string
    {
        if (!preg_match('/^\d+(\.\d{1,4})?$/D', $rate) || bccomp($rate, '1', 4) >= 0) {
            return '0.1000';
        }
        return $rate;
    }

    // D4：佣金 = total × (rate%/100)，写 DECIMAL(14,4) 前 bcround 到 4 位
    public static function settle(string $totalSales, string $ratePercent): array
    {
        $total      = Money::bcround($totalSales, 4);
        $commission = Money::bcround(bcmul($total, bcdiv($ratePercent, '100', 8), 8), 4);
        return [$total, $commission, bcsub($total, $commission, 4)];
    }
}
