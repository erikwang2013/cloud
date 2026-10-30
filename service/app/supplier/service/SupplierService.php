<?php
namespace App\supplier\service;

use App\supplier\model\Supplier;
use App\supplier\model\SupplierWithdraw;
use App\user\model\User;
use Illuminate\Database\Capsule\Manager as Capsule;

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

    public function requestWithdraw(int $supplierId, string $amount, array $accountInfo): void
    {
        Capsule::transaction(function () use ($supplierId, $amount, $accountInfo) {
            // 行锁串行化同一供应商并发提现（双击/重放），锁内重算余额再建单，防止超余额双提现
            Supplier::where('id', $supplierId)->lockForUpdate()->firstOrFail();

            // 先校验格式：bcmath 对非数字字符串抛 ValueError（500），边界输入统一转 422
            if (!preg_match('/^-?\d+(\.\d{1,4})?$/D', $amount)) {
                throw new \InvalidArgumentException('Invalid withdraw amount');
            }

            if (bccomp($amount, '0', 4) <= 0) {
                throw new \InvalidArgumentException('Withdraw amount must be positive');
            }

            $available = (string) Capsule::table('supplier_settlements')
                ->where('supplier_id', $supplierId)
                ->where('status', 'completed')
                ->value(Capsule::raw('COALESCE(SUM(payable), 0)'));

            // 已完成的提现单同样占用额度：只有 rejected/cancelled 释放，否则同一笔钱可反复提现
            $pending = (string) Capsule::table('supplier_withdraws')
                ->where('supplier_id', $supplierId)
                ->whereNotIn('status', ['rejected', 'cancelled'])
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
