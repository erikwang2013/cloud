<?php
namespace App\Supplier\Service;

use App\Supplier\Model\Supplier;
use App\Supplier\Model\SupplierSettlement;
use App\Supplier\Model\SupplierWithdraw;
use App\Order\Model\OrderItem;
use App\User\Model\User;

class SupplierService
{
    public function apply(int $userId, array $data): Supplier
    {
        if (Supplier::where('user_id', $userId)->exists()) {
            throw new \InvalidArgumentException('You already have a supplier application');
        }

        return Supplier::create([
            'user_id'          => $userId,
            'company_name'     => $data['company_name'],
            'contact_name'     => $data['contact_name'],
            'contact_phone'    => $data['contact_phone'],
            'contact_email'    => $data['contact_email'],
            'status'           => 'pending',
            'settlement_method'=> $data['settlement_method'] ?? 'bank',
        ]);
    }

    public function approve(int $supplierId, int $adminId): void
    {
        $supplier = Supplier::findOrFail($supplierId);
        $supplier->update([
            'status'      => 'active',
            'approved_by' => $adminId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        User::where('id', $supplier->user_id)->update(['role' => 'supplier']);
    }

    public function generateSettlement(int $supplierId, string $periodStart, string $periodEnd): SupplierSettlement
    {
        // 幂等：同一周期已存在结算单则直接返回，避免重复生成
        $existing = SupplierSettlement::where('supplier_id', $supplierId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();
        if ($existing) {
            return $existing;
        }

        $items = OrderItem::whereHas('order', function ($q) use ($periodStart, $periodEnd) {
                $q->where('status', 'completed')
                  ->whereBetween('paid_at', [$periodStart, $periodEnd]);
            })
            ->whereHas('sku.product', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId);
            })
            ->get();

        $totalSales = $items->sum('total_price');
        $commission = $items->sum(function ($item) {
            $rate = 0.10;
            return bcmul($item->total_price, (string)$rate, 4);
        });
        $payable = bcsub($totalSales, $commission, 4);

        return SupplierSettlement::create([
            'supplier_id'  => $supplierId,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'total_sales'  => $totalSales,
            'commission'   => $commission,
            'payable'      => $payable,
            'status'       => 'pending',
        ]);
    }

    public function requestWithdraw(int $supplierId, string $amount, array $accountInfo): void
    {
        $available = SupplierSettlement::where('supplier_id', $supplierId)
            ->where('status', 'completed')
            ->sum('payable');

        $pending = SupplierWithdraw::where('supplier_id', $supplierId)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        $withdrawable = bcsub($available, $pending, 4);

        if (bccomp($amount, $withdrawable, 4) > 0) {
            throw new \InvalidArgumentException('Insufficient withdrawable balance');
        }

        SupplierWithdraw::create([
            'supplier_id'  => $supplierId,
            'amount'       => $amount,
            'method'       => $accountInfo['method'],
            'account_info' => json_encode($accountInfo),
            'status'       => 'pending',
        ]);
    }
}
