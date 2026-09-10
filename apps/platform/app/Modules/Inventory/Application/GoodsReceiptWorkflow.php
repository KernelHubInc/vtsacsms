<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Inventory\Domain\Models\GoodsReceipt;
use App\Modules\Inventory\Domain\Models\GoodsReceiptLine;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Procurement\Application\Contracts\PurchaseOrderReceiptContract;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class GoodsReceiptWorkflow
{
    public function __construct(
        private CurrentTenant $tenant,
        private PurchaseOrderReceiptContract $purchaseOrders,
        private StockLedger $ledger,
        private OutboxRecorder $outbox,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   purchase_order_id: string, supplier_id: string, warehouse_id: string,
     *   receipt_number: string, supplier_delivery_reference?: string|null,
     *   received_at?: \DateTimeInterface|string|null,
     *   lines: list<array{
     *     purchase_order_line_id: string, item_id: string, uom_id: string,
     *     destination_bin_id: string, rejection_bin_id?: string|null,
     *     lot_id?: string|null, serial_id?: string|null, received_quantity_base: int
     *   }>
     * } $data
     */
    public function receive(array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data): GoodsReceipt {
            $warehouse = Warehouse::query()->whereKey($data['warehouse_id'])->where('is_active', true)->firstOrFail();
            if ($data['lines'] === []) {
                throw new DomainException('A goods receipt requires at least one line.');
            }
            foreach ($data['lines'] as $line) {
                $expected = $this->purchaseOrders->receivableLine(
                    $data['purchase_order_id'],
                    $line['purchase_order_line_id'],
                );
                if ($expected['supplier_id'] !== $data['supplier_id']
                    || $expected['item_id'] !== $line['item_id']
                    || $expected['uom_id'] !== $line['uom_id']
                    || $line['received_quantity_base'] <= 0
                    || $line['received_quantity_base'] > $expected['remaining_quantity_base']) {
                    throw new DomainException('The received line does not match the open purchase-order quantity.');
                }
                InventoryItem::query()->whereKey($line['item_id'])->where('is_active', true)->firstOrFail();
                $this->assertBinInWarehouse($line['destination_bin_id'], (string) $warehouse->getKey());
                if (($line['rejection_bin_id'] ?? null) !== null) {
                    $rejection = $this->assertBinInWarehouse($line['rejection_bin_id'], (string) $warehouse->getKey());
                    if (! in_array($rejection->stockLocation->custody_type, ['quarantine', 'damaged', 'returns'], true)) {
                        throw new DomainException('Rejected goods must be directed to controlled custody.');
                    }
                }
            }

            $receipt = GoodsReceipt::query()->create([
                'purchase_order_id' => $data['purchase_order_id'],
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $warehouse->getKey(),
                'receipt_number' => $data['receipt_number'],
                'status' => 'pending_inspection',
                'supplier_delivery_reference' => $data['supplier_delivery_reference'] ?? null,
                'received_by' => $this->actorId(),
                'received_at' => $data['received_at'] ?? now('UTC'),
            ]);
            foreach ($data['lines'] as $line) {
                $expected = $this->purchaseOrders->receivableLine(
                    $data['purchase_order_id'],
                    $line['purchase_order_line_id'],
                );
                GoodsReceiptLine::query()->create([
                    'goods_receipt_id' => $receipt->getKey(),
                    'purchase_order_line_id' => $line['purchase_order_line_id'],
                    'item_id' => $line['item_id'],
                    'uom_id' => $line['uom_id'],
                    'destination_bin_id' => $line['destination_bin_id'],
                    'rejection_bin_id' => $line['rejection_bin_id'] ?? null,
                    'lot_id' => $line['lot_id'] ?? null,
                    'serial_id' => $line['serial_id'] ?? null,
                    'received_quantity_base' => $line['received_quantity_base'],
                    'unit_cost_minor' => $expected['unit_cost_minor'],
                    'currency' => $expected['currency'],
                    'inspection_status' => 'pending',
                ]);
            }
            $this->audit->record(new AuditEntry(
                'inventory.goods_receipt.recorded',
                'goods_receipt',
                (string) $receipt->getKey(),
                AuditResult::Succeeded,
                after: $receipt->toArray(),
            ));

            return $receipt->load('lines');
        });
    }

    /**
     * @param list<array{
     *   line_id: string, accepted_quantity_base: int, rejected_quantity_base: int,
     *   inspection_notes?: string|null
     * }> $decisions
     */
    public function inspect(GoodsReceipt $receipt, array $decisions): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $decisions): GoodsReceipt {
            $locked = GoodsReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending_inspection') {
                throw new DomainException('Only pending receipts can be inspected.');
            }
            $byId = collect($decisions)->keyBy('line_id');
            foreach ($locked->lines()->lockForUpdate()->get() as $line) {
                $decision = $byId->get((string) $line->getKey());
                if (! is_array($decision)
                    || $decision['accepted_quantity_base'] < 0
                    || $decision['rejected_quantity_base'] < 0
                    || (int) $line->received_quantity_base
                        !== $decision['accepted_quantity_base'] + $decision['rejected_quantity_base']) {
                    throw new DomainException('Inspection decisions must account for every received unit.');
                }
                if ($decision['rejected_quantity_base'] > 0 && $line->rejection_bin_id === null) {
                    throw new DomainException('Rejected goods require a quarantine, damaged, or returns bin.');
                }
                $line->forceFill([
                    'accepted_quantity_base' => $decision['accepted_quantity_base'],
                    'rejected_quantity_base' => $decision['rejected_quantity_base'],
                    'inspection_status' => $decision['rejected_quantity_base'] === 0
                        ? 'accepted'
                        : ($decision['accepted_quantity_base'] === 0 ? 'rejected' : 'partially_accepted'),
                    'inspection_notes' => $decision['inspection_notes'] ?? null,
                ])->save();
            }
            $locked->forceFill([
                'status' => 'inspected',
                'inspected_by' => $this->actorId(),
                'inspected_at' => now('UTC'),
            ])->save();
            $this->audit->record(new AuditEntry(
                'inventory.goods_receipt.inspected',
                'goods_receipt',
                (string) $locked->getKey(),
                AuditResult::Succeeded,
            ));

            return $locked->load('lines');
        });
    }

    public function post(GoodsReceipt $receipt): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt): GoodsReceipt {
            $locked = GoodsReceipt::query()->with('lines')->whereKey($receipt->getKey())
                ->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'inspected') {
                throw new DomainException('Only inspected receipts can post stock.');
            }
            foreach ($locked->lines as $line) {
                if ((int) $line->accepted_quantity_base > 0) {
                    $this->ledger->post($this->movementData(
                        $locked,
                        $line,
                        (string) $line->destination_bin_id,
                        (int) $line->accepted_quantity_base,
                        'accepted',
                    ));
                }
                if ((int) $line->rejected_quantity_base > 0) {
                    $this->ledger->post($this->movementData(
                        $locked,
                        $line,
                        (string) $line->rejection_bin_id,
                        (int) $line->rejected_quantity_base,
                        'rejected',
                    ));
                }
            }
            $receivedLines = [];
            foreach ($locked->lines as $line) {
                $receivedLines[] = [
                    'purchase_order_line_id' => (string) $line->purchase_order_line_id,
                    'received_quantity_base' => (int) $line->received_quantity_base,
                    'accepted_quantity_base' => (int) $line->accepted_quantity_base,
                    'rejected_quantity_base' => (int) $line->rejected_quantity_base,
                ];
            }
            $this->purchaseOrders->registerReceipt(
                (string) $locked->purchase_order_id,
                (string) $locked->getKey(),
                $receivedLines,
            );
            $hasRejections = $locked->lines->contains(
                static fn (GoodsReceiptLine $line): bool => (int) $line->rejected_quantity_base > 0,
            );
            $locked->forceFill([
                'status' => $hasRejections ? 'posted_with_rejections' : 'posted',
                'posted_by' => $this->actorId(),
                'posted_at' => now('UTC'),
            ])->save();
            $this->outbox->record('inventory.goods_receipt.posted.v1', 'goods_receipt', (string) $locked->getKey(), [
                'purchase_order_id' => (string) $locked->purchase_order_id,
                'has_rejections' => $hasRejections,
            ]);

            return $locked;
        });
    }

    /**
     * @return array{
     *   item_id: string, uom_id: string, to_bin_id: string, lot_id: string|null,
     *   serial_id: string|null, movement_type: MovementType, quantity_base: int,
     *   currency: string, unit_cost_minor: int, reference_type: string,
     *   reference_id: string, reason_code: string, idempotency_key: string
     * }
     */
    private function movementData(
        GoodsReceipt $receipt,
        GoodsReceiptLine $line,
        string $toBinId,
        int $quantity,
        string $disposition,
    ): array {
        return [
            'item_id' => (string) $line->item_id,
            'uom_id' => (string) $line->uom_id,
            'to_bin_id' => $toBinId,
            'lot_id' => $line->lot_id,
            'serial_id' => $line->serial_id,
            'movement_type' => MovementType::Receipt,
            'quantity_base' => $quantity,
            'currency' => (string) $line->currency,
            'unit_cost_minor' => (int) $line->unit_cost_minor,
            'reference_type' => 'goods_receipt',
            'reference_id' => (string) $receipt->getKey(),
            'reason_code' => "inspection_{$disposition}",
            'idempotency_key' => "goods-receipt:{$receipt->getKey()}:{$line->getKey()}:{$disposition}",
        ];
    }

    private function assertBinInWarehouse(string $binId, string $warehouseId): InventoryBin
    {
        return InventoryBin::query()->with('stockLocation')->whereKey($binId)
            ->where('warehouse_id', $warehouseId)->where('is_active', true)->firstOrFail();
    }

    private function actorId(): string
    {
        return $this->tenant->get()->actorId
            ?? throw new DomainException('Inventory changes require an accountable actor.');
    }
}
