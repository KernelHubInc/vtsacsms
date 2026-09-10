<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Inventory\Domain\Models\StockTransferLine;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\TransferStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class StockTransferWorkflow
{
    public function __construct(
        private CurrentTenant $tenant,
        private StockLedger $ledger,
        private MovingAverageValuation $valuation,
        private OutboxRecorder $outbox,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param list<array{
     *   item_id: string, uom_id: string, source_bin_id: string, in_transit_bin_id: string,
     *   destination_bin_id: string, lot_id?: string|null, serial_id?: string|null,
     *   requested_quantity_base: int
     * }> $lines
     */
    public function create(
        string $transferNumber,
        string $sourceWarehouseId,
        string $destinationWarehouseId,
        array $lines,
    ): StockTransfer {
        return DB::transaction(function () use (
            $transferNumber, $sourceWarehouseId, $destinationWarehouseId, $lines,
        ): StockTransfer {
            if ($sourceWarehouseId === $destinationWarehouseId || $lines === []) {
                throw new DomainException('A transfer requires different warehouses and at least one line.');
            }
            Warehouse::query()->whereKey($sourceWarehouseId)->where('is_active', true)->firstOrFail();
            Warehouse::query()->whereKey($destinationWarehouseId)->where('is_active', true)->firstOrFail();
            foreach ($lines as $line) {
                if ($line['requested_quantity_base'] <= 0) {
                    throw new DomainException('Transfer quantities must be positive.');
                }
                InventoryItem::query()->whereKey($line['item_id'])->firstOrFail();
                InventoryBin::query()->whereKey($line['source_bin_id'])
                    ->where('warehouse_id', $sourceWarehouseId)->firstOrFail();
                InventoryBin::query()->whereKey($line['destination_bin_id'])
                    ->where('warehouse_id', $destinationWarehouseId)->firstOrFail();
                $transit = InventoryBin::query()->with('stockLocation')->whereKey($line['in_transit_bin_id'])
                    ->firstOrFail();
                if ($transit->stockLocation->custody_type !== 'in_transit') {
                    throw new DomainException('A transfer requires a dedicated in-transit custody bin.');
                }
            }
            $transfer = StockTransfer::query()->create([
                'transfer_number' => $transferNumber,
                'source_warehouse_id' => $sourceWarehouseId,
                'destination_warehouse_id' => $destinationWarehouseId,
                'status' => TransferStatus::Draft,
                'requested_by' => $this->actorId(),
            ]);
            foreach ($lines as $line) {
                StockTransferLine::query()->create([
                    'transfer_id' => $transfer->getKey(),
                    ...$line,
                ]);
            }

            return $transfer->load('lines');
        });
    }

    public function submit(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status !== TransferStatus::Draft) {
            throw new DomainException('Only a draft transfer can be submitted.');
        }
        $transfer->forceFill(['status' => TransferStatus::Submitted])->save();

        return $transfer;
    }

    public function approve(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status !== TransferStatus::Submitted) {
            throw new DomainException('Only a submitted transfer can be approved.');
        }
        $transfer->forceFill([
            'status' => TransferStatus::Approved,
            'approved_by' => $this->actorId(),
            'approved_at' => now('UTC'),
        ])->save();
        $this->audit->record(new AuditEntry(
            'inventory.transfer.approved',
            'stock_transfer',
            (string) $transfer->getKey(),
            AuditResult::Succeeded,
        ));

        return $transfer;
    }

    public function dispatch(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer): StockTransfer {
            $locked = StockTransfer::query()->with('lines')->whereKey($transfer->getKey())
                ->lockForUpdate()->firstOrFail();
            if ($locked->status !== TransferStatus::Approved) {
                throw new DomainException('Only an approved transfer can be dispatched.');
            }
            foreach ($locked->lines as $line) {
                $item = InventoryItem::query()->whereKey($line->item_id)->firstOrFail();
                $this->ledger->post([
                    'item_id' => (string) $line->item_id,
                    'uom_id' => (string) $line->uom_id,
                    'from_bin_id' => (string) $line->source_bin_id,
                    'to_bin_id' => (string) $line->in_transit_bin_id,
                    'lot_id' => $line->lot_id,
                    'serial_id' => $line->serial_id,
                    'movement_type' => MovementType::TransferDispatch,
                    'quantity_base' => (int) $line->requested_quantity_base,
                    'currency' => (string) $item->currency,
                    'unit_cost_minor' => $this->valuation->unitCostMinor($item),
                    'reference_type' => 'stock_transfer',
                    'reference_id' => (string) $locked->getKey(),
                    'reason_code' => 'transfer_dispatch',
                    'idempotency_key' => "transfer:{$locked->getKey()}:{$line->getKey()}:dispatch",
                ]);
                $line->forceFill(['dispatched_quantity_base' => $line->requested_quantity_base])->save();
            }
            $locked->forceFill([
                'status' => TransferStatus::Dispatched,
                'dispatched_by' => $this->actorId(),
                'dispatched_at' => now('UTC'),
            ])->save();
            $this->outbox->record('inventory.transfer.dispatched.v1', 'stock_transfer', (string) $locked->getKey(), [
                'source_warehouse_id' => (string) $locked->source_warehouse_id,
                'destination_warehouse_id' => (string) $locked->destination_warehouse_id,
            ]);

            return $locked;
        });
    }

    /** @param array<string, int> $receivedByLineId */
    public function receive(StockTransfer $transfer, array $receivedByLineId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $receivedByLineId): StockTransfer {
            $locked = StockTransfer::query()->with('lines')->whereKey($transfer->getKey())
                ->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [
                TransferStatus::Dispatched,
                TransferStatus::PartiallyReceived,
                TransferStatus::Discrepancy,
            ], true)) {
                throw new DomainException('This transfer is not awaiting receipt.');
            }
            $hasDiscrepancy = false;
            foreach ($locked->lines as $line) {
                $remaining = (int) $line->dispatched_quantity_base - (int) $line->received_quantity_base;
                $quantity = $receivedByLineId[(string) $line->getKey()] ?? 0;
                if ($quantity < 0 || $quantity > $remaining) {
                    throw new DomainException('Received transfer quantity is outside the dispatched balance.');
                }
                if ($quantity === 0) {
                    $hasDiscrepancy = $remaining > 0;

                    continue;
                }
                $item = InventoryItem::query()->whereKey($line->item_id)->firstOrFail();
                $this->ledger->post([
                    'item_id' => (string) $line->item_id,
                    'uom_id' => (string) $line->uom_id,
                    'from_bin_id' => (string) $line->in_transit_bin_id,
                    'to_bin_id' => (string) $line->destination_bin_id,
                    'lot_id' => $line->lot_id,
                    'serial_id' => $line->serial_id,
                    'movement_type' => MovementType::TransferReceive,
                    'quantity_base' => $quantity,
                    'currency' => (string) $item->currency,
                    'unit_cost_minor' => $this->valuation->unitCostMinor($item),
                    'reference_type' => 'stock_transfer',
                    'reference_id' => (string) $locked->getKey(),
                    'reason_code' => 'transfer_receive',
                    'idempotency_key' => "transfer:{$locked->getKey()}:{$line->getKey()}:receive:"
                        .((int) $line->received_quantity_base + $quantity),
                ]);
                $line->forceFill([
                    'received_quantity_base' => (int) $line->received_quantity_base + $quantity,
                ])->save();
                $hasDiscrepancy = $hasDiscrepancy
                    || (int) $line->received_quantity_base < (int) $line->dispatched_quantity_base;
            }
            $allReceived = $locked->lines()->whereColumn(
                'received_quantity_base',
                '<',
                'dispatched_quantity_base',
            )->doesntExist();
            $locked->forceFill([
                'status' => $allReceived
                    ? TransferStatus::Received
                    : ($hasDiscrepancy ? TransferStatus::Discrepancy : TransferStatus::PartiallyReceived),
                'received_by' => $this->actorId(),
                'received_at' => now('UTC'),
                'discrepancy_notes' => $hasDiscrepancy ? 'Received quantities differ from dispatch.' : null,
            ])->save();
            $this->outbox->record('inventory.transfer.received.v1', 'stock_transfer', (string) $locked->getKey(), [
                'status' => $locked->status->value,
            ]);

            return $locked->load('lines');
        });
    }

    private function actorId(): string
    {
        return $this->tenant->get()->actorId
            ?? throw new DomainException('Inventory changes require an accountable actor.');
    }
}
