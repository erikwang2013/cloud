<?php
namespace App\Cron;

use Common\Money\Money;

class SupplierSettlement
{
    public function run(): void
    {
        echo date('Y-m-d H:i:s') . " SupplierSettlement: Starting weekly settlement...\n";

        $suppliers = \App\Supplier\Model\Supplier::where('status', 'approved')->get();
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekEnd   = date('Y-m-d');

        foreach ($suppliers as $supplier) {
            try {
                $orders = \App\Order\Model\Order::whereHas('items', function ($q) use ($supplier) {
                    $q->whereHas('product', fn($q) => $q->where('supplier_id', $supplier->id));
                })
                ->whereBetween('created_at', [$weekStart . ' 00:00:00', $weekEnd . ' 23:59:59'])
                ->where('status', 'completed')
                ->get();

                if ($orders->isEmpty()) continue;

                // 幂等：同一供应商同一结算周期已存在则跳过，避免 cron 重复执行产生重复结算单
                $exists = \App\Supplier\Model\SupplierSettlement::where('supplier_id', $supplier->id)
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

                $settlement = \App\Supplier\Model\SupplierSettlement::create([
                    'supplier_id'  => $supplier->id,
                    'period_start' => $weekStart,
                    'period_end'   => $weekEnd,
                    'total_sales'  => $totalSales,
                    'commission'   => $commission,
                    'payable'      => $payable,
                    'status'       => 'pending',
                ]);

                \Common\Webhook\WebhookDispatcher::dispatch(
                    \Common\Webhook\WebhookDispatcher::EVENT_SETTLEMENT_CREATED,
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
