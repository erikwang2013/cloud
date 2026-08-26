<?php
namespace App\admin\controller;

use App\order\model\Order;
use App\user\model\User;
use App\user\model\UserKyc;
use App\provisioning\model\Resource;
use App\ticket\model\Ticket;
use Common\helper\Response;
use Common\security\AuditLogger;

class DashboardController
{
    public function index()
    {
        $today = date('Y-m-d');

        $todayOrders = Order::whereDate('created_at', $today)->count();
        $todayRevenue = Order::whereDate('paid_at', $today)->where('status', '!=', 'refunded')->sum('total');
        $newUsers     = User::whereDate('created_at', $today)->count();
        $activeResources = Resource::where('status', 'active')->count();

        $trend = Order::selectRaw('DATE(paid_at) as day, SUM(total) as total')
            ->where('status', '!=', 'refunded')
            ->where('paid_at', '>=', date('Y-m-d', strtotime('-29 days')) . ' 00:00:00')
            ->groupBy('day')
            ->pluck('total', 'day');

        $thirtyDays = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $thirtyDays[$date] = $trend[$date] ?? '0';
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
            'pending_kyc'       => UserKyc::where('status', 'pending')->count(),
            'open_tickets'      => Ticket::whereIn('status', ['open', 'in_progress'])->count(),
        ]));
    }

    public function kycList()
    {
        $kycs = UserKyc::with('user.profile')
            ->orderBy('created_at')
            ->paginate(20);
        return json(Response::paginated($kycs->items(), $kycs->total(), request()->input('page', 1), 20));
    }

    public function kycApprove($request, int $id)
    {
        $kyc = UserKyc::findOrFail($id);
        $kyc->update([
            'status'      => 'approved',
            'verified_by' => $request->userId,
            'verified_at' => date('Y-m-d H:i:s'),
        ]);

        AuditLogger::record('admin_kyc_approve', [
            'user_id' => $request->userId,
            'input'   => ['kyc_id' => $id, 'user_id' => $kyc->user_id],
        ], $request);

        return json(Response::success(null, 'KYC approved'));
    }

    public function kycReject($request, int $id)
    {
        $kyc = UserKyc::findOrFail($id);
        $kyc->update([
            'status'        => 'rejected',
            'verified_by'   => $request->userId,
            'reject_reason' => $request->input('reason'),
        ]);

        AuditLogger::record('admin_kyc_reject', [
            'user_id' => $request->userId,
            'input'   => ['kyc_id' => $id, 'user_id' => $kyc->user_id],
        ], $request);

        return json(Response::success(null, 'KYC rejected'));
    }
}
