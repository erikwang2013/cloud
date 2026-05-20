<?php
namespace App\Admin\Controller;

use App\Order\Model\Invoice;
use App\Order\Model\Order;
use Common\Helper\Response;

class InvoiceController
{
    public function index()
    {
        $invoices = Invoice::with('order')->orderBy('created_at', 'desc')->paginate(30);
        return json(Response::paginated($invoices->items(), $invoices->total(), (int) request()->input('page', 1), 30));
    }

    public function generate($request, int $orderId)
    {
        $order = Order::with('user', 'items')->findOrFail($orderId);

        if (!in_array($order->status, ['completed', 'paid'])) {
            return json(Response::error(422, 'Invoice can only be generated for paid/completed orders'));
        }

        $existing = Invoice::where('order_id', $orderId)->first();
        if ($existing) {
            return json(Response::success($existing, 'Invoice already exists'));
        }

        $profile = $order->user->profile;
        $taxId   = $profile?->tax_id;
        $type    = $taxId ? 'business' : 'personal';
        $invoice = Invoice::create([
            'order_id'   => $order->id,
            'user_id'    => $order->user_id,
            'type'       => $type,
            'title'      => ($type === 'business' && $profile) ? ($profile->company ?? 'Business') : ($order->user->email ?? ''),
            'tax_number' => $taxId,
            'amount'     => $order->total,
        ]);

        return json(Response::success($invoice, 'Invoice generated'));
    }
}
