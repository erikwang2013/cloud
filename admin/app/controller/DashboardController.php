<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\controller;

use app\common\Util;
use app\model\BusinessUser;
use app\model\Order;
use app\model\Resource;
use support\Request;
use support\Response;
use Workerman\Worker;

class DashboardController extends Base
{
    protected $noNeedAuth = [];

    /**
     * Dashboard data API — returns JSON for ECharts and stat cards.
     */
    public function index(Request $request): Response
    {
        $today = date('Y-m-d');

        // 业务用户（users 表，软删不计）：一条分组查询取近 30 天趋势，其余统计由结果推导
        $dailyUsers = BusinessUser::where('created_at', '>=', date('Y-m-d 00:00:00', strtotime('-29 days')))
            ->whereNull('deleted_at')
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        $countsByDate = [];
        foreach ($dailyUsers as $date => $count) {
            $countsByDate[(string) $date] = (int) $count;
        }

        $userTrend = [];
        $userTrend30d = [];
        $monthUsers = 0;
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $count = $countsByDate[$date] ?? 0;
            $monthUsers += $count;
            $userTrend30d[] = ['date' => substr($date, 5), 'count' => $count];
            if ($i < 7) {
                $userTrend[] = ['date' => substr($date, 5), 'count' => $count];
            }
        }
        $todayUsers = $countsByDate[$today] ?? 0;
        $weekUsers = array_sum(array_column($userTrend, 'count'));
        $totalUsers = BusinessUser::whereNull('deleted_at')->count();

        // Service stats
        $todayStart = $today . ' 00:00:00';
        $todayOrders = Order::where('paid_at', '>=', $todayStart)
            ->where('status', '!=', 'refunded')->count();
        // 多币种不可直接相加：orders.exchange_rate 为外币/USD 汇率（USD=1.0），USD 金额 = total / rate
        $todayRevenue = Order::where('paid_at', '>=', $todayStart)
            ->where('status', '!=', 'refunded')
            ->selectRaw('COALESCE(ROUND(SUM(total / NULLIF(exchange_rate, 0)), 4), 0) as revenue')->value('revenue');
        $pendingOrders = Order::where('status', 'pending')->count();
        $activeResources = Resource::where('status', 'active')->count();

        // System info
        $version = Util::db()->select('select VERSION() as version');
        $mysqlVersion = $version[0]->version ?? 'unknown';

        return $this->json(0, 'ok', [
            'stats' => [
                'today_users'       => $todayUsers,
                'week_users'        => $weekUsers,
                'month_users'       => $monthUsers,
                'total_users'       => $totalUsers,
                'today_orders'      => $todayOrders,
                'today_revenue'     => $todayRevenue,
                'pending_orders'    => $pendingOrders,
                'active_resources'  => $activeResources,
            ],
            'user_trend_7d'  => $userTrend,
            'user_trend_30d' => $userTrend30d,
            'system' => [
                'php_version'      => PHP_VERSION,
                'workerman_version' => Worker::VERSION,
                'webman_version'   => Util::getPackageVersion('workerman/webman-framework'),
                'admin_version'    => Util::getPackageVersion('webman/admin'),
                'mysql_version'    => $mysqlVersion,
                'os'               => PHP_OS,
            ],
        ]);
    }
}
