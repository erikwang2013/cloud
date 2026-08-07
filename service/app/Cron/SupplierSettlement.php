<?php
namespace App\Cron;

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

                $totalSales = $orders->sum('total');
                $commission = $totalSales * ((float) ($supplier->commission_rate ?? 10) / 100);
                $payable    = $totalSales - $commission;

                \App\Supplier\Model\SupplierSettlement::create([
                    'supplier_id'  => $supplier->id,
                    'period_start' => $weekStart,
                    'period_end'   => $weekEnd,
                    'total_sales'  => $totalSales,
                    'commission'   => $commission,
                    'payable'      => $payable,
                    'status'       => 'pending',
                ]);

                echo "  Supplier #{$supplier->id}: Sales={$totalSales}, Payable={$payable}\n";
            } catch (\Throwable $e) {
                echo "  Supplier #{$supplier->id}: ERROR - {$e->getMessage()}\n";
            }
        }

        echo date('Y-m-d H:i:s') . " SupplierSettlement: Done.\n";
    }
}
