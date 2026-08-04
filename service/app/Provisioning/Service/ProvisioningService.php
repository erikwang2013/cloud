<?php
namespace App\Provisioning\Service;

use App\Provisioning\Model\ProvisionTask;
use App\Order\Model\Order;
use App\Order\Model\OrderItem;
use App\Order\Model\OrderTimeline;

class ProvisioningService
{
    private ProviderFactory $factory;

    public function __construct()
    {
        $this->factory = new ProviderFactory();
    }

    public function getFactory(): ProviderFactory
    {
        return $this->factory;
    }

    public function handleOrderPaid(Order $order): void
    {
        $order->update(['status' => 'provisioning']);
        OrderTimeline::create([
            'order_id' => $order->id,
            'status'   => 'provisioning',
            'operator' => 'system',
            'remark'   => 'Provisioning started',
        ]);

        foreach ($order->items as $item) {
            ProvisionTask::create([
                'order_id'      => $order->id,
                'order_item_id' => $item->id,
                'resource_id'   => null,
                'product_type'  => $this->getProductType($item),
                'provider'      => $this->resolveProvider($item),
                'region_id'     => $item->region_id,
                'action'        => 'create',
                'status'        => 'pending',
                'params'        => json_encode([
                    'specs'       => $item->sku->specs ?? [],
                    'quantity'    => $item->quantity,
                    'cycle'       => $item->cycle,
                    'resource_snapshot' => $item->resource_snapshot,
                ]),
                'retry_count'   => 0,
                'next_retry_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function getProductType(OrderItem $item): string
    {
        $categoryType = $item->sku->product->category->type ?? null;
        if ($categoryType) {
            return $categoryType;
        }
        // Legacy fallback for categories without type column populated
        $categoryId = $item->sku->product->category_id ?? 0;
        $map = [1 => 'server', 2 => 'ip', 3 => 'disk', 4 => 'domain'];
        return $map[$categoryId] ?? 'server';
    }

    private function resolveProvider(OrderItem $item): string
    {
        $product = $item->sku->product;
        // Check for explicit provider override on the product
        if (!empty($product->provider)) {
            return $product->provider;
        }
        // Default to proxmox — the only provider currently implemented
        return 'proxmox';
    }
}
