<?php
namespace App\supplier\controller\external;

use App\supplier\model\Supplier;
use App\supplier\model\SupplierSettlement;
use Common\helper\Response;

class SettlementController
{
    public function index($request)
    {
        $supplier = Supplier::findOrFail($request->supplierId);
        $status   = $request->input('status');

        $query = SupplierSettlement::where('supplier_id', $supplier->id);
        if ($status) {
            $query->where('status', $status);
        }

        $settlements = $query->orderBy('created_at', 'desc')->paginate(20);

        return json(Response::paginated(
            $settlements->items(),
            $settlements->total(),
            (int)$request->input('page', 1),
            20
        ));
    }

    public function show($request, int $id)
    {
        $supplier   = Supplier::findOrFail($request->supplierId);
        $settlement = SupplierSettlement::where('id', $id)
            ->where('supplier_id', $supplier->id)
            ->firstOrFail();

        return json(Response::success($settlement));
    }
}
