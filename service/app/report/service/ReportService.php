<?php
namespace App\report\service;

use App\order\model\Order;
use App\supplier\model\SupplierSettlement;
use Illuminate\Database\Capsule\Manager as DB;

class ReportService
{
    public function revenueReport(string $startDate, string $endDate): array
    {
        $daily = Order::whereBetween('paid_at', [$startDate, $endDate])
            ->where('status', '!=', 'refunded')
            ->selectRaw('DATE(paid_at) as date, currency, SUM(total) as revenue, COUNT(*) as orders')
            ->groupBy('date', 'currency')
            ->orderBy('date')
            ->get();

        // D4：汇总统一 bcmath（Eloquent sum() 走浮点），金额以字符串 4 位小数输出
        $totalRevenue = array_reduce($daily->all(), fn ($c, $row) => bcadd($c, (string) $row->revenue, 4), '0.0000');
        $totalOrders  = $daily->sum('orders');

        $byCategory = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_skus', 'product_skus.id', '=', 'order_items.sku_id')
            ->join('products', 'products.id', '=', 'product_skus.product_id')
            ->join('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->whereBetween('orders.paid_at', [$startDate, $endDate])
            ->where('orders.status', '!=', 'refunded')
            ->selectRaw('product_categories.id, product_categories.name, SUM(order_items.total_price) as revenue')
            ->groupBy('product_categories.id', 'product_categories.name')
            ->get();

        return compact('daily', 'totalRevenue', 'totalOrders', 'byCategory');
    }

    public function supplierReport(int $supplierId, string $startDate, string $endDate): array
    {
        $settlements = SupplierSettlement::where('supplier_id', $supplierId)
            ->whereBetween('period_start', [$startDate, $endDate])
            ->get();

        $totalPayable = array_reduce($settlements->all(), fn ($c, $row) => bcadd($c, (string) $row->payable, 4), '0.0000');
        $totalPaid    = array_reduce($settlements->where('status', 'paid')->all(), fn ($c, $row) => bcadd($c, (string) $row->payable, 4), '0.0000');

        return compact('settlements', 'totalPayable', 'totalPaid');
    }

    public function salesByRegion(string $startDate, string $endDate): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('regions', 'regions.id', '=', 'order_items.region_id')
            ->whereBetween('orders.paid_at', [$startDate, $endDate])
            ->where('orders.status', '!=', 'refunded')
            ->selectRaw('regions.name, regions.continent, COUNT(*) as orders, SUM(order_items.total_price) as revenue')
            ->groupBy('regions.id', 'regions.name', 'regions.continent')
            ->orderBy('revenue', 'desc')
            ->get()
            ->toArray();
    }
}
