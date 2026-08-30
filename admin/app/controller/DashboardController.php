<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\controller;

use app\common\Util;
use app\model\Order;
use app\model\Resource;
use app\model\User;
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
        $weekAgo = date('Y-m-d H:i:s', time() - 7 * 24 * 60 * 60);
        $monthAgo = date('Y-m-d H:i:s', time() - 30 * 24 * 60 * 60);

        // User stats from local wa_users
        $todayUsers = User::where('created_at', '>', $today . ' 00:00:00')->count();
        $weekUsers = User::where('created_at', '>', $weekAgo)->count();
        $monthUsers = User::where('created_at', '>', $monthAgo)->count();
        $totalUsers = User::count();

        // 7-day user registration trend
        $userTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', time() - $i * 24 * 60 * 60);
            $count = User::where('created_at', '>=', "$date 00:00:00")
                ->where('created_at', '<', date('Y-m-d', strtotime($date) + 86400) . ' 00:00:00')
                ->count();
            $userTrend[] = ['date' => substr($date, 5), 'count' => $count];
        }

        // 30-day user registration trend
        $userTrend30d = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', time() - $i * 24 * 60 * 60);
            $count = User::where('created_at', '>=', "$date 00:00:00")
                ->where('created_at', '<', date('Y-m-d', strtotime($date) + 86400) . ' 00:00:00')
                ->count();
            $userTrend30d[] = ['date' => substr($date, 5), 'count' => $count];
        }

        // Service stats
        $todayStart = $today . ' 00:00:00';
        $todayOrders = Order::where('paid_at', '>=', $todayStart)
            ->where('status', '!=', 'refunded')->count();
        // 多币种不可直接相加：orders.exchange_rate 为币种→USD 汇率（USD=1.0），折算为 USD 基准币种
        $todayRevenue = Order::where('paid_at', '>=', $todayStart)
            ->where('status', '!=', 'refunded')
            ->selectRaw('COALESCE(SUM(total * exchange_rate), 0) as revenue')->value('revenue');
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
