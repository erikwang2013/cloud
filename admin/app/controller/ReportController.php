<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\controller;

use app\common\ExcelExport;
use app\model\BusinessUser;
use app\model\Order;
use app\model\OrderItem;
use app\model\PaymentChannel;
use app\model\PaymentTransaction;
use app\model\Product;
use support\exception\BusinessException;
use support\Request;
use support\Response;

/**
 * 报表统计：订单日报 / 商品销量排行 / 支付渠道统计 / 用户增长，支持 Excel 导出。
 */
class ReportController extends Base
{
    protected $noNeedAuth = [];

    /**
     * 报表页面
     */
    public function index(): Response
    {
        return raw_view('report/index');
    }

    /**
     * 订单日报表：按天聚合订单数与营收（排除 refunded，按 paid_at 计）
     */
    public function order(Request $request): Response
    {
        [$start, $end] = $this->parseRange($request);

        $daily = Order::whereBetween('paid_at', [$start, $end])
            ->where('status', '!=', 'refunded')
            ->selectRaw('DATE(paid_at) as date, currency, SUM(total) as revenue, COUNT(*) as orders')
            ->groupBy('date', 'currency')
            ->orderBy('date')
            ->get();

        // D4：金额汇总用 bcmath，避免浮点误差；DECIMAL 列由 PDO 以字符串返回
        // 多币种场景下营收不能跨币种相加，按币种分组汇总
        $totalOrders = $daily->sum('orders');
        $revenueByCurrency = [];
        foreach ($daily->all() as $row) {
            $revenueByCurrency[$row->currency] = bcadd($revenueByCurrency[$row->currency] ?? '0.0000', (string) $row->revenue, 4);
        }

        return $this->json(0, 'ok', [
            'range'  => ['start' => $start, 'end' => $end],
            'totals' => ['orders' => $totalOrders, 'revenue_by_currency' => $revenueByCurrency],
            'daily'  => $daily->toArray(),
        ]);
    }

    /**
     * 商品销量排行：按销量倒序取前 N
     */
    public function product_top(Request $request): Response
    {
        [$start, $end] = $this->parseRange($request);
        $limit = min(max((int) $request->get('limit', 10), 1), 50);

        // amount 折算为 USD 基准币种（orders.exchange_rate 为币种→USD 汇率），避免跨币种相加
        $items = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.paid_at', [$start, $end])
            ->where('orders.status', '!=', 'refunded')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty, SUM(order_items.total_price * orders.exchange_rate) as amount')
            ->groupBy('order_items.product_id')
            ->orderByDesc('qty')
            ->limit($limit)
            ->get()
            ->toArray();

        $ids = array_column($items, 'product_id');
        $names = $ids ? Product::whereIn('id', $ids)->pluck('name', 'id') : collect();
        foreach ($items as &$item) {
            $item['name'] = $names[$item['product_id']] ?? '-';
        }
        unset($item);

        return $this->json(0, 'ok', [
            'range' => ['start' => $start, 'end' => $end],
            'items' => $items,
        ]);
    }

    /**
     * 支付渠道统计：按渠道+币种聚合成功交易
     */
    public function payment(Request $request): Response
    {
        [$start, $end] = $this->parseRange($request);

        $items = PaymentTransaction::query()
            ->join('payment_channels', 'payment_channels.id', '=', 'payment_transactions.channel_id')
            ->where('payment_transactions.status', 'success')
            ->whereBetween('payment_transactions.created_at', [$start, $end])
            ->selectRaw('payment_channels.name, payment_transactions.currency, COUNT(*) as transactions, SUM(payment_transactions.amount) as amount')
            ->groupBy('payment_channels.id', 'payment_channels.name', 'payment_transactions.currency')
            ->orderByDesc('transactions')
            ->get()
            ->toArray();

        foreach ($items as &$item) {
            $item['channel'] = $item['name'];
            unset($item['name']);
        }
        unset($item);

        return $this->json(0, 'ok', [
            'range' => ['start' => $start, 'end' => $end],
            'items' => $items,
        ]);
    }

    /**
     * 用户增长：业务用户按天注册数（软删不计）
     */
    public function user_growth(Request $request): Response
    {
        [$start, $end] = $this->parseRange($request);

        $items = BusinessUser::whereBetween('created_at', [$start, $end])
            ->whereNull('deleted_at')
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        return $this->json(0, 'ok', [
            'range' => ['start' => $start, 'end' => $end],
            'items' => $items,
        ]);
    }

    /**
     * Excel 导出：type 白名单 {order,product,payment,user}
     */
    public function export(Request $request): Response
    {
        [$start, $end] = $this->parseRange($request);
        $type = (string) $request->get('type', '');

        switch ($type) {
            case 'order':
                $title = '订单日报表';
                $columns = ['date', 'currency', 'orders', 'revenue'];
                $labels = ['date' => '日期', 'currency' => '币种', 'orders' => '订单数', 'revenue' => '营收'];
                $rows = array_map(function ($row) {
                    return ['date' => $row->date, 'currency' => $row->currency, 'orders' => $row->orders, 'revenue' => $row->revenue];
                }, Order::whereBetween('paid_at', [$start, $end])
                    ->where('status', '!=', 'refunded')
                    ->selectRaw('DATE(paid_at) as date, currency, COUNT(*) as orders, SUM(total) as revenue')
                    ->groupBy('date', 'currency')->orderBy('date')->get()->all());
                break;
            case 'product':
                $limit = min(max((int) $request->get('limit', 10), 1), 50);
                $title = '商品销量排行';
                $columns = ['name', 'qty', 'amount'];
                $labels = ['name' => '商品名称', 'qty' => '销量', 'amount' => '金额'];
                $items = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereBetween('orders.paid_at', [$start, $end])
                    ->where('orders.status', '!=', 'refunded')
                    ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty, SUM(order_items.total_price * orders.exchange_rate) as amount')
                    ->groupBy('order_items.product_id')->orderByDesc('qty')->limit($limit)->get();
                $names = Product::whereIn('id', $items->pluck('product_id')->all())->pluck('name', 'id');
                $rows = array_map(function ($row) use ($names) {
                    return ['name' => $names[$row->product_id] ?? '-', 'qty' => $row->qty, 'amount' => $row->amount];
                }, $items->all());
                break;
            case 'payment':
                $title = '支付渠道统计';
                $columns = ['channel', 'currency', 'transactions', 'amount'];
                $labels = ['channel' => '支付渠道', 'currency' => '币种', 'transactions' => '笔数', 'amount' => '金额'];
                $rows = array_map(function ($row) {
                    return ['channel' => $row->name, 'currency' => $row->currency, 'transactions' => $row->transactions, 'amount' => $row->amount];
                }, PaymentTransaction::query()
                    ->join('payment_channels', 'payment_channels.id', '=', 'payment_transactions.channel_id')
                    ->where('payment_transactions.status', 'success')
                    ->whereBetween('payment_transactions.created_at', [$start, $end])
                    ->selectRaw('payment_channels.name, payment_transactions.currency, COUNT(*) as transactions, SUM(payment_transactions.amount) as amount')
                    ->groupBy('payment_channels.id', 'payment_channels.name', 'payment_transactions.currency')
                    ->orderByDesc('transactions')->get()->all());
                break;
            case 'user':
                $title = '用户增长报表';
                $columns = ['date', 'count'];
                $labels = ['date' => '日期', 'count' => '新增用户'];
                $rows = array_map(function ($row) {
                    return ['date' => $row->date, 'count' => $row->count];
                }, BusinessUser::whereBetween('created_at', [$start, $end])
                    ->whereNull('deleted_at')
                    ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                    ->groupBy('date')->orderBy('date')->get()->all());
                break;
            default:
                throw new BusinessException('未知导出类型', 1);
        }

        $path = ExcelExport::export($title, $columns, $rows, $labels);
        return response()->download($path, $title . '_' . date('YmdHis') . '.xlsx');
    }

    /**
     * 解析并校验日期区间，非法时抛 BusinessException。
     *
     * @return array{0: string, 1: string} [start, end] 完整时间串
     */
    private function parseRange(Request $request): array
    {
        $start = (string) $request->get('start_date', '');
        $end = (string) $request->get('end_date', '');
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        if ($start !== '' && !preg_match($re, $start)) {
            throw new BusinessException('开始日期格式应为 YYYY-MM-DD', 1);
        }
        if ($end !== '' && !preg_match($re, $end)) {
            throw new BusinessException('结束日期格式应为 YYYY-MM-DD', 1);
        }
        if ($start === '') {
            $start = date('Y-m-d', time() - 29 * 86400);
        }
        if ($end === '') {
            $end = date('Y-m-d');
        }
        if ($start > $end) {
            throw new BusinessException('开始日期不能晚于结束日期', 1);
        }
        return [$start . ' 00:00:00', $end . ' 23:59:59'];
    }
}
