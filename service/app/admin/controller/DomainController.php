<?php
namespace App\admin\controller;

use App\domain\model\DomainTld;
use App\domain\model\DnsZone;
use App\domain\model\DomainTransfer;
use Common\helper\CacheService;
use Common\helper\Response;

class DomainController
{
    // TLD management
    public function tlds()
    {
        $tlds = DomainTld::orderBy('tld')->get();
        return json(Response::success($tlds));
    }

    public function storeTld($request)
    {
        $tld = DomainTld::create($request->only(['tld', 'wholesale_price', 'retail_price', 'registrar', 'promo_price', 'promo_end_at']));
        CacheService::forget('tlds:all');
        return json(Response::success($tld));
    }

    public function updateTld($request, int $id)
    {
        $tld = DomainTld::findOrFail($id);
        $tld->update($request->only(['wholesale_price', 'retail_price', 'registrar', 'promo_price', 'promo_end_at']));
        CacheService::forget('tlds:all');
        return json(Response::success($tld));
    }

    public function deleteTld(int $id)
    {
        DomainTld::destroy($id);
        return json(Response::success());
    }

    // DNS zone management
    public function zones()
    {
        $zones = DnsZone::with('records')->orderBy('created_at', 'desc')->paginate(30);
        return json(Response::success($zones));
    }

    // Domain transfer management
    public function transfers()
    {
        $transfers = DomainTransfer::with('user')->orderBy('created_at', 'desc')->paginate(30);
        return json(Response::paginated($transfers->items(), $transfers->total(), (int) request()->input('page', 1), 30));
    }

    public function approveTransfer(int $id)
    {
        $transfer = DomainTransfer::findOrFail($id);
        $transfer->update(['status' => 'approved']);
        return json(Response::success(null, 'Transfer approved'));
    }
}
