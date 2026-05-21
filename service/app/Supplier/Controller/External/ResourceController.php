<?php
namespace App\Supplier\Controller\External;

use App\Provisioning\Model\Resource;
use App\Supplier\Model\Supplier;
use Common\Helper\Response;

class ResourceController
{
    public function index($request)
    {
        $supplier = Supplier::findOrFail($request->supplierId);
        $status   = $request->input('status');
        $type     = $request->input('type');

        $query = Resource::whereHas('orderItem.sku.product', function ($q) use ($supplier) {
            $q->where('supplier_id', $supplier->id);
        });

        if ($status) {
            $query->where('status', $status);
        }
        if ($type) {
            $query->where('type', $type);
        }

        $pageSize  = min((int)$request->input('page_size', 20), 50);
        $resources = $query->orderBy('created_at', 'desc')->paginate($pageSize);

        return json(Response::paginated(
            $resources->items(),
            $resources->total(),
            (int)$request->input('page', 1),
            $pageSize
        ));
    }

    public function status($request, int $id)
    {
        $supplier = Supplier::findOrFail($request->supplierId);
        $resource = Resource::whereHas('orderItem.sku.product', function ($q) use ($supplier) {
            $q->where('supplier_id', $supplier->id);
        })->findOrFail($id);

        return json(Response::success([
            'id'     => $resource->id,
            'type'   => $resource->type,
            'status' => $resource->status,
            'provisioned_at' => $resource->provisioned_at,
            'expired_at'     => $resource->expired_at,
        ]));
    }
}
