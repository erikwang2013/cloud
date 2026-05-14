<?php
namespace App\Admin\Controller;

use App\Supplier\Model\Supplier;
use App\Supplier\Model\SupplierWithdraw;
use App\Supplier\Service\SupplierService;
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
}
