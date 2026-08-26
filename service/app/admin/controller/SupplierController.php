<?php
namespace App\admin\controller;

use App\supplier\model\Supplier;
use App\supplier\model\SupplierWithdraw;
use App\supplier\service\SupplierService;
use Common\ExcelExport;
use Common\helper\Response;
use Common\security\AuditLogger;
use Common\webhook\WebhookDispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;

class SupplierController
{
    public function index($request)
    {
        $query = Supplier::with('user');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $suppliers = $query->orderBy('created_at', 'desc')->paginate(30);
        return json(Response::paginated($suppliers->items(), $suppliers->total(), $request->input('page', 1), 30));
    }

    public function export($request)
    {
        $query = Supplier::with('user');

        if ($status = $request->input('status')) $query->where('status', $status);

        $maxRows = 10000;
        // 模型 $hidden 默认隐藏联系人 PII；导出为受控 admin 功能，显式恢复
        $items = $query->orderBy('created_at', 'desc')->limit($maxRows)->get()
            ->makeVisible(['contact_name', 'contact_email', 'contact_phone'])
            ->toArray();

        $columns = ['id', 'user_id', 'status', 'contact_name', 'contact_email', 'contact_phone', 'created_at'];
        $labels = [
            'id' => 'ID', 'user_id' => '用户ID', 'status' => '状态',
            'contact_name' => '联系人', 'contact_email' => '联系邮箱',
            'contact_phone' => '联系电话', 'created_at' => '申请时间',
        ];

        $path = ExcelExport::export('suppliers', $columns, $items, $labels);
        return response()->download($path, 'suppliers_' . date('YmdHis') . '.xlsx');
    }

    public function approve($request, int $id)
    {
        $service = new SupplierService();
        $service->approve($id, $request->userId);

        AuditLogger::record('admin_supplier_approve', [
            'user_id' => $request->userId,
            'input'   => ['supplier_id' => $id],
        ], $request);

        return json(Response::success(null, 'Supplier approved'));
    }

    public function generateSettlement($request, int $id)
    {
        $service = new SupplierService();
        $settlement = $service->generateSettlement(
            $id,
            $request->input('period_start'),
            $request->input('period_end')
        );

        AuditLogger::record('admin_supplier_settlement', [
            'user_id' => $request->userId,
            'input'   => [
                'supplier_id'   => $id,
                'period_start'  => $request->input('period_start'),
                'period_end'    => $request->input('period_end'),
                'settlement_id' => $settlement->id ?? null,
            ],
        ], $request);

        return json(Response::success($settlement, 'Settlement generated'));
    }

    public function approveWithdraw($request, int $id)
    {
        $withdraw = SupplierWithdraw::findOrFail($id);

        try {
            Capsule::transaction(function () use ($withdraw, $id, $request) {
                // 行锁 + 状态守卫：仅允许 pending 状态的提现审批，防止重复审批
                $locked = SupplierWithdraw::where('id', $withdraw->id)->lockForUpdate()->first();
                if (!$locked || $locked->status !== 'pending') {
                    throw new \RuntimeException("Withdrawal #{$id} is not pending, cannot approve");
                }

                $withdraw->update([
                    'status'     => 'completed',
                    'handled_by' => $request->userId,
                    'handled_at' => now(),
                ]);
            });
        } catch (\RuntimeException $e) {
            return json(Response::error(400, $e->getMessage()));
        }

        WebhookDispatcher::dispatch(WebhookDispatcher::EVENT_WITHDRAWAL_APPROVED, [
            'withdraw_id' => $withdraw->id,
            'supplier_id' => $withdraw->supplier_id,
            'amount'      => (string) $withdraw->amount,
        ]);

        AuditLogger::record('admin_supplier_withdraw_approve', [
            'user_id' => $request->userId,
            'input'   => [
                'withdraw_id' => $id,
                'supplier_id' => $withdraw->supplier_id,
                'amount'      => $withdraw->amount,
                'handled_by'  => $request->userId,
            ],
        ], $request);

        return json(Response::success(null, 'Withdrawal approved'));
    }

    public function apiKeys(int $supplierId)
    {
        $keys = \Illuminate\Database\Capsule\Manager::table('supplier_api_keys')
            ->where('supplier_id', $supplierId)
            ->where('revoked', false)
            ->get()
            ->map(fn($k) => ['id' => $k->id, 'name' => $k->name, 'key_prefix' => $k->key_prefix, 'created_at' => $k->created_at, 'last_used_at' => $k->last_used_at]);

        return json(Response::success($keys));
    }

    public function createApiKey($request, int $supplierId)
    {
        $rawKey = 'sk_' . bin2hex(random_bytes(24));
        $prefix = substr($rawKey, 0, 10);

        \Illuminate\Database\Capsule\Manager::table('supplier_api_keys')->insert([
            'supplier_id' => $supplierId,
            'name'        => $request->input('name', 'API Key'),
            'key_hash'    => hash('sha256', $rawKey),
            'key_prefix'  => $prefix,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Return raw key only once
        return json(Response::success(['api_key' => $rawKey, 'prefix' => $prefix], 'API key created. Store it securely.'));
    }

    public function revokeApiKey(int $id)
    {
        \Illuminate\Database\Capsule\Manager::table('supplier_api_keys')
            ->where('id', $id)
            ->update(['revoked' => true, 'updated_at' => now()]);

        return json(Response::success());
    }
}
