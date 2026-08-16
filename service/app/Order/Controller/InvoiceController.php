<?php
namespace App\Order\Controller;

use App\Order\Model\Invoice;
use App\Order\Model\Order;
use Common\Helper\Response;

class InvoiceController
{
    public function index($request)
    {
        $invoices = Invoice::where('user_id', $request->userId)
            ->with('order')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return json(Response::paginated($invoices->items(), $invoices->total(), $request->input('page', 1), 20));
    }

    public function show($request, int $id)
    {
        $invoice = Invoice::where('id', $id)->where('user_id', $request->userId)->with('order.items')->firstOrFail();
        return json(Response::success($invoice));
    }

    public function download($request, int $id)
    {
        $invoice = Invoice::where('id', $id)->where('user_id', $request->userId)->firstOrFail();

        // 已有真实 PDF 文件（file_url）时直接下发
        if ($invoice->file_url) {
            $safePath = realpath(storage_path(ltrim($invoice->file_url, '/')));
            $storageRoot = realpath(storage_path());
            if ($safePath && str_starts_with($safePath, $storageRoot) && file_exists($safePath)) {
                return response()->download($safePath);
            }
        }

        // 兜底：以规范 HTML 发票页呈现（含中文、可打印/另存为 PDF）。
        // 此前以 application/pdf 头返回 HTML 字符串，下载的是损坏文件。
        $html = $this->renderHtmlInvoice($invoice);
        return response($html, 200, [
            'Content-Type'        => 'text/html; charset=utf-8',
            'Content-Disposition' => 'inline; filename="invoice_' . $invoice->id . '.html"',
        ]);
    }

    private function renderHtmlInvoice(Invoice $invoice): string
    {
        $order = $invoice->order()->with('items')->first();
        $h = fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

        $itemsHtml = '';
        if ($order) {
            foreach ($order->items as $item) {
                $name = $item->resource_snapshot['name']
                    ?? $item->resource_snapshot['product_name']
                    ?? ('SKU #' . $item->sku_id);
                $itemsHtml .= '<tr>'
                    . '<td>' . $h($name) . '</td>'
                    . '<td>' . $h($item->quantity) . '</td>'
                    . '<td>' . $h($item->cycle ?? '') . '</td>'
                    . '<td class="num">' . $h($item->unit_price) . '</td>'
                    . '<td class="num">' . $h($item->total_price) . '</td>'
                    . '</tr>';
            }
        }

        $currency = $order->currency ?? '';
        $total = $order->total ?? $invoice->amount;

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Invoice #' . $h($invoice->id) . '</title>'
            . '<style>'
            . 'body{font-family:-apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;'
            . 'max-width:760px;margin:32px auto;padding:0 20px;color:#222;}'
            . 'h1{font-size:22px;margin-bottom:4px;}'
            . '.muted{color:#666;font-size:13px;}'
            . 'table{width:100%;border-collapse:collapse;margin-top:24px;}'
            . 'th,td{border-bottom:1px solid #e3e3e3;padding:10px 8px;text-align:left;font-size:14px;}'
            . 'th{background:#f7f7f7;}'
            . '.num{text-align:right;}'
            . '.totals{margin-top:16px;text-align:right;font-size:14px;}'
            . '.totals p{margin:4px 0;}'
            . '.total{font-weight:600;font-size:16px;}'
            . '</style></head><body>'
            . '<h1>Invoice #' . $h($invoice->id) . '</h1>'
            . '<p class="muted">Date: ' . $h($invoice->created_at) . '</p>'
            . '<p class="muted">Billed to: ' . $h($invoice->title) . '</p>'
            . '<p class="muted">Tax Number: ' . $h($invoice->tax_number ?: 'N/A') . '</p>'
            . ($order ? '<p class="muted">Order: #' . $h($order->order_no) . ' &middot; Status: ' . $h($order->status) . '</p>' : '')
            . '<table><thead><tr>'
            . '<th>Item</th><th>Qty</th><th>Cycle</th><th class="num">Unit Price</th><th class="num">Total</th>'
            . '</tr></thead><tbody>' . $itemsHtml . '</tbody></table>'
            . '<div class="totals">'
            . ($order ? '<p>Subtotal: ' . $h($order->subtotal) . ' ' . $h($currency) . '</p>' : '')
            . ($order && (float) $order->discount ? '<p>Discount: -' . $h($order->discount) . ' ' . $h($currency) . '</p>' : '')
            . ($order ? '<p>Tax: ' . $h($order->tax) . ' ' . $h($currency) . '</p>' : '')
            . '<p class="total">Total: ' . $h($total) . ' ' . $h($currency) . '</p>'
            . '</div>'
            . '<p class="muted" style="margin-top:24px">This invoice can be printed or saved as PDF from your browser.</p>'
            . '</body></html>';
    }
}
