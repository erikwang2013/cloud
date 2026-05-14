<?php
namespace App\Admin\Controller;

use App\Order\Model\Order;
use App\User\Model\User;
use App\Provisioning\Model\Resource;
use Common\Helper\Response;

class DashboardController
{
    public function index()
    {
        $today = date('Y-m-d');

        $todayOrders = Order::whereDate('created_at', $today)->count();
        $todayRevenue = Order::whereDate('paid_at', $today)->where('status', '!=', 'refunded')->sum('total');
        $newUsers     = User::whereDate('created_at', $today)->count();
        $activeResources = Resource::where('status', 'active')->count();

        $thirtyDays = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $thirtyDays[$date] = Order::whereDate('paid_at', $date)
                ->where('status', '!=', 'refunded')
                ->sum('total');
        }

        $regionDistribution = Resource::where('status', 'active')
            ->selectRaw('region_id, count(*) as count')
            ->groupBy('region_id')
            ->with('region')
            ->get();

        return json(Response::success([
            'today_stats'       => compact('todayOrders', 'todayRevenue', 'newUsers', 'activeResources'),
            'revenue_trend_30d' => $thirtyDays,
            'region_distribution' => $regionDistribution,
            'pending_orders'    => Order::where('status', 'pending')->count(),
            'pending_kyc'       => \App\User\Model\UserKyc::where('status', 'pending')->count(),
            'open_tickets'      => \App\Ticket\Model\Ticket::whereIn('status', ['open', 'in_progress'])->count(),
        ]));
    }

    public function kycList()
    {
        $kycs = \App\User\Model\UserKyc::with('user.profile')
            ->orderBy('created_at')
            ->paginate(20);
        return json(Response::paginated($kycs->items(), $kycs->total(), request()->input('page', 1), 20));
    }

    public function kycApprove($request, int $id)
    {
        $kyc = \App\User\Model\UserKyc::findOrFail($id);
        $kyc->update([
            'status'      => 'approved',
            'verified_by' => $request->userId,
            'verified_at' => date('Y-m-d H:i:s'),
        ]);
        return json(Response::success(null, 'KYC approved'));
    }

    public function kycReject($request, int $id)
    {
        $kyc = \App\User\Model\UserKyc::findOrFail($id);
        $kyc->update([
            'status'        => 'rejected',
            'verified_by'   => $request->userId,
            'reject_reason' => $request->input('reason'),
        ]);
        return json(Response::success(null, 'KYC rejected'));
    }
}
