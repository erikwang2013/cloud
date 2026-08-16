<?php
namespace App\Admin\Controller;

use App\Affiliate\Model\AffiliateEarning;
use App\Affiliate\Model\AffiliatePayout;
use App\Affiliate\Service\AffiliateService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Common\Helper\Response;
use Common\Security\AuditLogger;

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

    public function approveEarning($request, int $id)
    {
        // 状态机守卫：仅 pending 可批准，避免重复审批
        $earning = AffiliateEarning::where('id', $id)->where('status', 'pending')->first();
        if (!$earning) {
            return json(Response::error(422, 'Earning is not pending or not found'));
        }

        $earning->update(['status' => 'approved']);

        // 通知推广人佣金已入账（通知非关键，失败不影响审批结果）
        try {
            (new \App\Notification\Service\NotificationDispatcher())->dispatch(
                $earning->affiliate_id, 'affiliate_earning_credited',
                ['amount' => (string) $earning->amount, 'currency' => $earning->currency],
                ['email', 'in_app']
            );
        } catch (\Throwable) {
            // 忽略通知异常
        }

        AuditLogger::record('admin_affiliate_earning_approve', [
            'user_id' => $request->userId,
            'input'   => ['earning_id' => $id],
        ], $request);

        return json(Response::success(null, 'Earning approved'));
    }

    public function payouts()
    {
        $payouts = AffiliatePayout::orderBy('created_at', 'desc')->get();
        return json(Response::success($payouts));
    }

    public function approvePayout($request, int $id)
    {
        $service = new AffiliateService();
        try {
            $service->approvePayout($id);
        } catch (\RuntimeException $e) {
            return json(Response::error(400, $e->getMessage()));
        }

        AuditLogger::record('admin_affiliate_payout_approve', [
            'user_id' => $request->userId,
            'input'   => ['payout_id' => $id],
        ], $request);

        return json(Response::success(null, 'Payout approved'));
    }
}
