<?php
namespace Common\hashid\middleware;

use Common\hashid\HashidService;

class HashidRequestMiddleware
{
    /** Keys in request input that may contain hashids */
    private array $idKeys = ['id', 'user_id', 'order_id', 'product_id', 'sku_id',
        'resource_id', 'ticket_id', 'supplier_id', 'region_id', 'category_id',
        'channel_id', 'zone_id', 'disk_id', 'pool_id', 'host_machine_id',
        'order_item_id', 'assigned_to', 'closed_by', 'verified_by', 'parent_id'];

    public function process($request, callable $next)
    {
        $inputs = $request->all();
        if (!empty($inputs)) {
            $request->rawInputs = $inputs;
            $this->decodeInputs($inputs);
            // Replace request inputs with decoded versions
            foreach ($inputs as $key => $value) {
                $request->{$key} = $value;
            }
        }
        return $next($request);
    }

    private function decodeInputs(array &$data): void
    {
        foreach ($data as $key => &$value) {
            if (in_array($key, $this->idKeys, true) && is_string($value)) {
                $decoded = HashidService::decode($value);
                if ($decoded !== null) {
                    $value = $decoded;
                }
            } elseif (is_array($value)) {
                $this->decodeInputs($value);
            }
        }
        unset($value);
    }
}
