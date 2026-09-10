<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\StockLot;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\StockReservation;
use App\Modules\Inventory\Domain\Models\StockSerial;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\ReservationStatus;
use App\Modules\Inventory\Domain\TrackingType;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class StockLedger
{
    public function __construct(
        private CurrentTenant $tenant,
        private StockAvailabilityQuery $availability,
        private OutboxRecorder $outbox,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   item_id: string, uom_id: string, from_bin_id?: string|null, to_bin_id?: string|null,
     *   lot_id?: string|null, serial_id?: string|null, movement_type: MovementType|string,
     *   quantity_base: int, currency: string, unit_cost_minor: int,
     *   reference_type: string, reference_id: string, reason_code?: string|null,
     *   idempotency_key: string, reservation_id?: string|null, posted_by?: string|null,
     *   occurred_at?: \DateTimeInterface|string|null, reverses_movement_id?: string|null
     * } $data
     */
    public function post(array $data): StockMovement
    {
        return DB::transaction(function () use ($data): StockMovement {
            $existing = StockMovement::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                return $existing;
            }

            $quantity = $data['quantity_base'];
            $unitCost = $data['unit_cost_minor'];
            if ($quantity <= 0 || $unitCost < 0) {
                throw new DomainException('Stock movement quantity must be positive and unit cost cannot be negative.');
            }

            $from = $this->lockBin($data['from_bin_id'] ?? null);
            $to = $this->lockBin($data['to_bin_id'] ?? null);
            if ($from === null && $to === null) {
                throw new DomainException('A stock movement requires a source or destination bin.');
            }
            if ($from !== null && $to !== null && $from->is($to)) {
                throw new DomainException('A stock movement cannot use the same source and destination bin.');
            }

            $item = InventoryItem::query()->whereKey($data['item_id'])->lockForUpdate()->firstOrFail();
            StockReservation::query()->where('item_id', $item->getKey())->lockForUpdate()->get();
            $this->validateTracking($item, $data, $from);

            if ($from !== null) {
                $stock = $this->availability
                    ->forItem((string) $item->getKey(), (string) $from->warehouse_id, (string) $from->getKey());
                $available = $from->stockLocation->custody_type === 'available'
                    ? $stock->availableBase()
                    : $stock->onHandBase;
                if ($from->stockLocation->custody_type === 'available') {
                    $available += $this->reservationAllowance($data['reservation_id'] ?? null, $item, $from);
                }
                if ($available < $quantity) {
                    throw new DomainException('Insufficient available stock for this movement.');
                }
            }

            $actorId = $data['posted_by'] ?? $this->tenant->get()->actorId;
            if ($actorId === null) {
                throw new DomainException('A stock movement requires an accountable actor.');
            }

            $movementType = $data['movement_type'] instanceof MovementType
                ? $data['movement_type']
                : MovementType::from($data['movement_type']);
            $movement = StockMovement::query()->create([
                'item_id' => $item->getKey(),
                'uom_id' => $data['uom_id'],
                'from_bin_id' => $from?->getKey(),
                'to_bin_id' => $to?->getKey(),
                'lot_id' => $data['lot_id'] ?? null,
                'serial_id' => $data['serial_id'] ?? null,
                'movement_type' => $movementType,
                'quantity_base' => $quantity,
                'currency' => mb_strtoupper($data['currency']),
                'unit_cost_minor' => $unitCost,
                'total_cost_minor' => $quantity * $unitCost,
                'reference_type' => $data['reference_type'],
                'reference_id' => $data['reference_id'],
                'reason_code' => $data['reason_code'] ?? null,
                'idempotency_key' => $data['idempotency_key'],
                'reverses_movement_id' => $data['reverses_movement_id'] ?? null,
                'posted_by' => $actorId,
                'occurred_at' => $data['occurred_at'] ?? now('UTC'),
                'posted_at' => now('UTC'),
            ]);

            if (($data['serial_id'] ?? null) !== null) {
                StockSerial::query()->whereKey($data['serial_id'])->update([
                    'current_bin_id' => $to?->getKey(),
                    'status' => $to === null ? 'issued' : 'available',
                ]);
            }

            $eventType = match ($movementType) {
                MovementType::Receipt => 'inventory.goods.received.v1',
                MovementType::Issue, MovementType::WorkOrderIssue => 'inventory.stock.issued.v1',
                MovementType::CustomerReturn, MovementType::WorkOrderReturn => 'inventory.stock.returned.v1',
                MovementType::TransferDispatch => 'inventory.transfer.dispatched.v1',
                MovementType::TransferReceive => 'inventory.transfer.received.v1',
                MovementType::AdjustmentIncrease, MovementType::AdjustmentDecrease, MovementType::Reversal => 'inventory.stock.adjusted.v1',
                MovementType::SupplierReturn => 'inventory.stock.returned_to_supplier.v1',
            };
            $this->outbox->record($eventType, 'stock_movement', (string) $movement->getKey(), [
                'item_id' => (string) $item->getKey(),
                'from_bin_id' => $from?->getKey(),
                'to_bin_id' => $to?->getKey(),
                'quantity_base' => $quantity,
                'movement_type' => $movementType->value,
                'reference_type' => $data['reference_type'],
                'reference_id' => $data['reference_id'],
            ]);
            $this->audit->record(new AuditEntry(
                'inventory.stock_movement.posted',
                'stock_movement',
                (string) $movement->getKey(),
                AuditResult::Succeeded,
                reason: $data['reason_code'] ?? null,
                after: $movement->toArray(),
            ));

            return $movement;
        });
    }

    public function reverse(StockMovement $movement, string $reasonCode, string $idempotencyKey): StockMovement
    {
        if ($movement->reverses_movement_id !== null) {
            throw new DomainException('A reversal movement cannot itself be reversed automatically.');
        }

        return $this->post([
            'item_id' => (string) $movement->item_id,
            'uom_id' => (string) $movement->uom_id,
            'from_bin_id' => $movement->to_bin_id,
            'to_bin_id' => $movement->from_bin_id,
            'lot_id' => $movement->lot_id,
            'serial_id' => $movement->serial_id,
            'movement_type' => MovementType::Reversal,
            'quantity_base' => (int) $movement->quantity_base,
            'currency' => (string) $movement->currency,
            'unit_cost_minor' => (int) $movement->unit_cost_minor,
            'reference_type' => 'stock_movement_reversal',
            'reference_id' => (string) $movement->getKey(),
            'reason_code' => $reasonCode,
            'idempotency_key' => $idempotencyKey,
            'reverses_movement_id' => (string) $movement->getKey(),
        ]);
    }

    private function lockBin(?string $binId): ?InventoryBin
    {
        if ($binId === null) {
            return null;
        }

        return InventoryBin::query()->with('stockLocation')->whereKey($binId)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $data */
    private function validateTracking(InventoryItem $item, array $data, ?InventoryBin $from): void
    {
        $lotId = $data['lot_id'] ?? null;
        $serialId = $data['serial_id'] ?? null;

        if ($item->tracking_type === TrackingType::Serial) {
            if ($serialId === null || $data['quantity_base'] !== 1) {
                throw new DomainException('Serialized items require exactly one serial per movement.');
            }
            $serial = StockSerial::query()->whereKey($serialId)->where('item_id', $item->getKey())
                ->lockForUpdate()->firstOrFail();
            if ($from !== null && ! hash_equals((string) $from->getKey(), (string) $serial->current_bin_id)) {
                throw new DomainException('The serial is not held in the source bin.');
            }

            return;
        }

        if ($item->tracking_type === TrackingType::Lot) {
            if ($lotId === null) {
                throw new DomainException('Lot-tracked items require a lot for every movement.');
            }
            StockLot::query()->whereKey($lotId)->where('item_id', $item->getKey())->lockForUpdate()->firstOrFail();

            return;
        }

        if ($lotId !== null || $serialId !== null) {
            throw new DomainException('Untracked items cannot carry lot or serial identifiers.');
        }
    }

    private function reservationAllowance(
        ?string $reservationId,
        InventoryItem $item,
        InventoryBin $from,
    ): int {
        if ($reservationId === null) {
            return 0;
        }

        $reservation = StockReservation::query()->whereKey($reservationId)->lockForUpdate()->firstOrFail();
        if ($reservation->item_id !== $item->getKey()
            || $reservation->warehouse_id !== $from->warehouse_id
            || ($reservation->bin_id !== null && $reservation->bin_id !== $from->getKey())
            || ! in_array($reservation->status, [
                ReservationStatus::PartiallyReserved,
                ReservationStatus::Reserved,
                ReservationStatus::PartiallyConsumed,
            ], true)) {
            throw new DomainException('The reservation does not authorize stock from this bin.');
        }

        return (int) $reservation->reserved_quantity_base - (int) $reservation->consumed_quantity_base;
    }
}
