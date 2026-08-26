<?php
namespace App\supplier\controller\external;

use App\supplier\model\Supplier;
use App\supplier\model\SupplierWithdraw;
use App\supplier\service\SupplierService;
use Common\helper\Response;

class WithdrawController
{
    private SupplierService $service;

    public function __construct()
    {
        $this->service = new SupplierService();
    }

    public function store($request)
    {
        try {
            $this->service->requestWithdraw(
                $request->supplierId,
                $request->input('amount'),
                $request->input('account_info', [])
            );
            return json(Response::success(null, 'Withdrawal requested'));
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(422, $e->getMessage()));
        }
    }

    public function index($request)
    {
        $supplier = Supplier::findOrFail($request->supplierId);

        $withdrawals = SupplierWithdraw::where('supplier_id', $supplier->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return json(Response::paginated(
            $withdrawals->items(),
            $withdrawals->total(),
            (int)$request->input('page', 1),
            20
        ));
    }
}
