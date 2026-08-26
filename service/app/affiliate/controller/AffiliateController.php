<?php
namespace App\affiliate\controller;

use App\affiliate\model\AffiliateLink;
use App\affiliate\model\AffiliateEarning;
use App\affiliate\model\AffiliatePayout;
use App\affiliate\service\AffiliateService;
use Common\helper\Response;

class AffiliateController
{
    public function summary($request)
    {
        $userId = $request->userId;
        $link = AffiliateLink::where('user_id', $userId)->first();
        $totalEarned = AffiliateEarning::where('affiliate_id', $userId)->sum('amount');
        $totalPending = AffiliateEarning::where('affiliate_id', $userId)->where('status', 'pending')->sum('amount');
        $totalPaid = AffiliatePayout::where('affiliate_id', $userId)->where('status', 'paid')->sum('amount');

        return json(Response::success([
            'referral_code' => $link->code ?? null,
            'total_earned'  => $totalEarned,
            'total_pending' => $totalPending,
            'total_paid'    => $totalPaid,
            'available'     => bcsub((string)$totalEarned, (string)$totalPaid, 4),
        ]));
    }

    public function generateLink($request)
    {
        $userId = $request->userId;
        $source = $request->input('source');
        $service = new AffiliateService();
        $link = $service->generateLink($userId, $source);

        return json(Response::success(['code' => $link->code, 'source' => $link->source], 'Referral link generated'));
    }

    public function earnings($request)
    {
        $userId = $request->userId;
        $earnings = AffiliateEarning::where('affiliate_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();
        return json(Response::success($earnings));
    }

    public function requestPayout($request)
    {
        $userId = $request->userId;
        try {
            $service = new AffiliateService();
            $payout = $service->requestPayout($userId);
            return json(Response::success($payout, 'Payout requested'));
        } catch (\RuntimeException $e) {
            return json(Response::error(400, $e->getMessage()));
        }
    }
}
