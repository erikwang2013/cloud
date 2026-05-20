<?php
namespace App\Admin\Controller;

use App\Supplier\Model\Supplier;
use App\Supplier\Model\SupplierWithdraw;
use App\Supplier\Service\SupplierService;
use Common\ExcelExport;
use Common\Helper\Response;

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
        $items = $query->orderBy('created_at', 'desc')->limit($maxRows)->get()->toArray();

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
        return json(Response::success($settlement, 'Settlement generated'));
    }

    public function approveWithdraw($request, int $id)
    {
        $withdraw = SupplierWithdraw::findOrFail($id);
        $withdraw->update(['status' => 'completed']);
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
