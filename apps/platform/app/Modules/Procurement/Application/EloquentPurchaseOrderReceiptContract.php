<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Procurement\Application\Contracts\PurchaseOrderReceiptContract;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\PurchaseOrderLine;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class EloquentPurchaseOrderReceiptContract implements PurchaseOrderReceiptContract
{
    public function __construct(private OutboxRecorder $outbox) {}

    public function receivableLine(string $purchaseOrderId, string $purchaseOrderLineId): array
    {
        $order = PurchaseOrder::query()->whereKey($purchaseOrderId)->firstOrFail();
        if (! in_array($order->status, [
            PurchaseOrderStatus::Issued,
            PurchaseOrderStatus::PartiallyReceived,
        ], true)) {
            throw new DomainException('Only issued purchase orders can receive stock.');
        }

        $line = PurchaseOrderLine::query()
            ->whereKey($purchaseOrderLineId)
            ->where('purchase_order_id', $order->getKey())
            ->firstOrFail();
        $remaining = (int) $line->ordered_quantity_base - (int) $line->accepted_quantity_base;
        if ($remaining <= 0) {
            throw new DomainException('The purchase-order line is already fully received.');
        }
        if ($line->item_id === null || $line->uom_id === null) {
            throw new DomainException('Inventory receipts require a catalog item and unit of measure.');
        }

        return [
            'purchase_order_id' => (string) $order->getKey(),
            'purchase_order_line_id' => (string) $line->getKey(),
            'supplier_id' => (string) $order->supplier_id,
            'item_id' => (string) $line->item_id,
            'uom_id' => (string) $line->uom_id,
            'remaining_quantity_base' => $remaining,
            'unit_cost_minor' => (int) $line->unit_price_minor,
            'currency' => (string) $order->currency,
        ];
    }

    public function registerReceipt(string $purchaseOrderId, string $receiptId, array $lines): void
    {
        DB::transaction(function () use ($purchaseOrderId, $receiptId, $lines): void {
            $order = PurchaseOrder::query()->whereKey($purchaseOrderId)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [
                PurchaseOrderStatus::Issued,
                PurchaseOrderStatus::PartiallyReceived,
            ], true)) {
                throw new DomainException('The purchase order can no longer receive stock.');
            }

            foreach ($lines as $receiptLine) {
                $line = PurchaseOrderLine::query()
                    ->whereKey($receiptLine['purchase_order_line_id'])
                    ->where('purchase_order_id', $order->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $received = (int) $line->received_quantity_base + $receiptLine['received_quantity_base'];
                $line->forceFill([
                    'received_quantity_base' => $received,
                    'accepted_quantity_base' => (int) $line->accepted_quantity_base
                        + $receiptLine['accepted_quantity_base'],
                ])->save();
            }

            $complete = ! PurchaseOrderLine::query()
                ->where('purchase_order_id', $order->getKey())
                ->whereColumn('accepted_quantity_base', '<', 'ordered_quantity_base')
                ->exists();
            $order->forceFill([
                'status' => $complete ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived,
            ])->save();
            $this->outbox->record('procurement.purchase_order.receipt_registered.v1', 'purchase_order', (string) $order->getKey(), [
                'receipt_id' => $receiptId,
                'status' => $order->status->value,
            ]);
        });
    }
}
