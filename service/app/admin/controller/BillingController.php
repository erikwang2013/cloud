<?php
namespace App\admin\controller;

use Common\helper\Response;
use Illuminate\Database\Capsule\Manager as Capsule;

class BillingController
{
    public function rates()
    {
        $rates = Capsule::table('usage_rates')->orderBy('meter')->get();
        return json(Response::success($rates));
    }

    public function storeRate($request)
    {
        Capsule::table('usage_rates')->insert([
            'sku_id'     => $request->input('sku_id'),
            'region_id'  => $request->input('region_id'),
            'meter'      => $request->input('meter'),
            'unit_price' => $request->input('unit_price'),
            'currency'   => $request->input('currency', 'USD'),
            'unit'       => $request->input('unit', 'GB'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return json(Response::success(null, 'Rate created'));
    }

    public function updateRate($request, int $id)
    {
        Capsule::table('usage_rates')->where('id', $id)->update([
            'unit_price' => $request->input('unit_price'),
            'currency'   => $request->input('currency'),
            'unit'       => $request->input('unit'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return json(Response::success(null, 'Rate updated'));
    }

    public function destroyRate(int $id)
    {
        Capsule::table('usage_rates')->where('id', $id)->delete();
        return json(Response::success(null, 'Rate deleted'));
    }

    public function usage()
    {
        $items = Capsule::table('usage_invoice_items')
            ->orderBy('created_at', 'desc')
            ->limit(500)
            ->get();
        return json(Response::success($items));
    }
}
