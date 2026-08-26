<?php
namespace App\cron;

use Common\money\Money;

class SupplierSettlement
{
    public function run(): void
    {
        echo date('Y-m-d H:i:s') . " SupplierSettlement: Starting weekly settlement...\n";

        $suppliers = \App\supplier\model\Supplier::where('status', 'approved')->get();
        // 上一完整周 [上周一, 上周日]：cron 周一 04:17 跑，若用 'monday this week' 周一解析为
        // 当天 → 窗口退化为单天，周二~周日完成的订单永不结算；以 last sunday 锚点回推 6 天，
        // 任意一天补跑窗口都恒定不漂移、不倒挂
        $weekEnd   = date('Y-m-d', strtotime('last sunday'));
        $weekStart = date('Y-m-d', strtotime('-6 days', strtotime($weekEnd)));

        foreach ($suppliers as $supplier) {
            try {
                // 结算周期按订单完成时间归属（order_timeline 的 completed 记录），
                // 而非 created_at：跨周下单、本周才交付完成的订单应进入本周结算批次
                $orders = \App\order\model\Order::whereHas('items', function ($q) use ($supplier) {
                    // OrderItem 无 product 关系（只有 sku→product 链），直接 whereHas('product') 会抛
                    // 异常被 catch 吞掉导致结算静默跳过；与 SupplierRatingService 同款链
                    $q->whereHas('sku', fn($q) => $q->whereHas('product', fn($q) => $q->where('supplier_id', $supplier->id)));
                })
                ->whereHas('timeline', function ($q) use ($weekStart, $weekEnd) {
                    $q->where('status', 'completed')
                      ->whereBetween('created_at', [$weekStart . ' 00:00:00', $weekEnd . ' 23:59:59']);
                })
                ->where('status', 'completed')
                ->get();

                if ($orders->isEmpty()) continue;

                // 幂等：同一供应商同一结算周期已存在则跳过，避免 cron 重复执行产生重复结算单
                $exists = \App\supplier\model\SupplierSettlement::where('supplier_id', $supplier->id)
                    ->where('period_start', $weekStart)
                    ->where('period_end', $weekEnd)
                    ->exists();
                if ($exists) continue;

                // D4：结算金额全程字符串 bcmath（Collection sum 会走浮点，逐行同精度累加）
                $totalSales = '0.0000';
                foreach ($orders as $order) {
                    $totalSales = bcadd($totalSales, (string) $order->total, 4);
                }
                [$totalSales, $commission, $payable] = self::settle($totalSales, (string) ($supplier->commission_rate ?? 10));

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
                    // 并发 cron 实例撞唯一索引：视为已生成，跳过（同 admin generateSettlement 语义）
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

    // D4：佣金 = total × (rate%/100)，写 DECIMAL(14,4) 前 bcround 到 4 位
    public static function settle(string $totalSales, string $ratePercent): array
    {
        $total      = Money::bcround($totalSales, 4);
        $commission = Money::bcround(bcmul($total, bcdiv($ratePercent, '100', 8), 8), 4);
        return [$total, $commission, bcsub($total, $commission, 4)];
    }
}
