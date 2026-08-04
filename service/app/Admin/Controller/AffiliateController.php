<?php
namespace App\Admin\Controller;

use App\Affiliate\Model\AffiliateEarning;
use App\Affiliate\Model\AffiliatePayout;
use App\Affiliate\Service\AffiliateService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Common\Helper\Response;

class AffiliateController
{
    public function plans()
    {
        $plans = Capsule::table('affiliate_plans')->get();
        return json(Response::success($plans));
    }

    public function storePlan($request)
    {
        Capsule::table('affiliate_plans')->insert([
            'name'             => $request->input('name'),
            'commission_rate'  => $request->input('commission_rate'),
            'tier'             => $request->input('tier', 1),
            'min_payout'       => $request->input('min_payout', 50),
            'lifetime_commissions' => $request->input('lifetime_commissions', false),
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        return json(Response::success(null, 'Plan created'));
    }

    public function earnings()
    {
        $earnings = AffiliateEarning::orderBy('created_at', 'desc')->limit(200)->get();
        return json(Response::success($earnings));
    }

    public function approveEarning(int $id)
    {
        AffiliateEarning::where('id', $id)->update(['status' => 'approved']);
        return json(Response::success(null, 'Earning approved'));
    }

    public function payouts()
    {
        $payouts = AffiliatePayout::orderBy('created_at', 'desc')->get();
        return json(Response::success($payouts));
    }

    public function approvePayout(int $id)
    {
        $service = new AffiliateService();
        $service->approvePayout($id);
        return json(Response::success(null, 'Payout approved'));
    }
}
