<?php
namespace App\supplier\service;

use App\supplier\model\Supplier;
use App\supplier\model\SupplierSettlement;
use App\supplier\model\SupplierWithdraw;
use App\order\model\OrderItem;
use App\user\model\User;
use Common\webhook\WebhookDispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;

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

        // D4：逐行字符串 bcmath 累计（Collection sum 走浮点），佣金沿用逐项 10% 舍入语义
        $totalSales = '0.0000';
        $commission = '0.0000';
        foreach ($items as $item) {
            $totalSales  = bcadd($totalSales, (string) $item->total_price, 4);
            $commission  = bcadd($commission, bcmul((string) $item->total_price, '0.1000', 4), 4);
        }
        $payable = bcsub($totalSales, $commission, 4);

        try {
            $settlement = SupplierSettlement::create([
                'supplier_id'  => $supplierId,
                'period_start' => $periodStart,
                'period_end'   => $periodEnd,
                'total_sales'  => $totalSales,
                'commission'   => $commission,
                'payable'      => $payable,
                'status'       => 'pending',
            ]);
        } catch (QueryException $e) {
            // 唯一索引 uniq_supplier_settlement_period 兜底：并发重复生成时返回既有结算单
            if (($e->errorInfo[0] ?? '') !== '23000') {
                throw $e;
            }
            return SupplierSettlement::where('supplier_id', $supplierId)
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->firstOrFail();
        }

        WebhookDispatcher::dispatch(WebhookDispatcher::EVENT_SETTLEMENT_CREATED, [
            'settlement_id' => $settlement->id,
            'supplier_id'   => $supplierId,
            'amount'        => (string) $payable,
            'period_start'  => $periodStart,
            'period_end'    => $periodEnd,
        ]);

        return $settlement;
    }

    public function requestWithdraw(int $supplierId, string $amount, array $accountInfo): void
    {
        Capsule::transaction(function () use ($supplierId, $amount, $accountInfo) {
            // 行锁串行化同一供应商并发提现（双击/重放），锁内重算余额再建单，防止超余额双提现
            Supplier::where('id', $supplierId)->lockForUpdate()->firstOrFail();

            if (bccomp($amount, '0', 4) <= 0) {
                throw new \InvalidArgumentException('Withdraw amount must be positive');
            }

            $available = (string) Capsule::table('supplier_settlements')
                ->where('supplier_id', $supplierId)
                ->where('status', 'completed')
                ->value(Capsule::raw('COALESCE(SUM(payable), 0)'));

            $pending = (string) Capsule::table('supplier_withdraws')
                ->where('supplier_id', $supplierId)
                ->whereIn('status', ['pending', 'processing'])
                ->value(Capsule::raw('COALESCE(SUM(amount), 0)'));

            $withdrawable = bcsub($available, $pending, 4);

            if (bccomp($amount, $withdrawable, 4) > 0) {
                throw new \InvalidArgumentException('Insufficient withdrawable balance');
            }

            SupplierWithdraw::create([
                'supplier_id'  => $supplierId,
                'amount'       => $amount,
                'method'       => $accountInfo['method'],
                // array cast 会自动 json_encode，传入字符串会双编码存成字面量
                'account_info' => $accountInfo,
                'status'       => 'pending',
            ]);
        });
    }
}
