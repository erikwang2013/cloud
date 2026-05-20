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

        if ($invoice->file_url) {
            $safePath = realpath(storage_path(ltrim($invoice->file_url, '/')));
            $storageRoot = realpath(storage_path());
            if ($safePath && str_starts_with($safePath, $storageRoot) && file_exists($safePath)) {
                return response()->download($safePath);
            }
        }

        // Generate PDF on the fly
        $pdf = $this->generatePdf($invoice);
        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="invoice_' . $invoice->id . '.pdf"',
        ]);
    }

    private function generatePdf(Invoice $invoice): string
    {
        $order = $invoice->order()->with('items')->first();
        $h = fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $html = '<html><body>';
        $html .= '<h1>Invoice #' . $h($invoice->id) . '</h1>';
        $html .= '<p>Date: ' . $h($invoice->created_at) . '</p>';
        $html .= '<p>Title: ' . $h($invoice->title) . '</p>';
        $html .= '<p>Tax Number: ' . $h($invoice->tax_number ?: 'N/A') . '</p>';
        $html .= '<p>Amount: ' . $h($invoice->amount) . '</p>';
        if ($order) {
            $html .= '<h2>Order #' . $h($order->order_no) . '</h2>';
            $html .= '<p>Status: ' . $h($order->status) . '</p>';
        }
        $html .= '</body></html>';
        return $html;
    }
}
