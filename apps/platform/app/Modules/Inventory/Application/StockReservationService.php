<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\StockReservation;
use App\Modules\Inventory\Domain\ReservationStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class StockReservationService
{
    public function __construct(
        private CurrentTenant $tenant,
        private StockAvailabilityQuery $availability,
        private OutboxRecorder $outbox,
    ) {}

    /**
     * @param array{
     *   item_id: string, warehouse_id: string, bin_id?: string|null, lot_id?: string|null,
     *   serial_id?: string|null, requested_quantity_base: int, purpose_type: string,
     *   purpose_id: string, idempotency_key: string, expires_at?: \DateTimeInterface|string|null,
     *   allow_partial?: bool
     * } $data
     */
    public function reserve(array $data): StockReservation
    {
        return DB::transaction(function () use ($data): StockReservation {
            $existing = StockReservation::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($data['requested_quantity_base'] <= 0) {
                throw new DomainException('Reservation quantity must be positive.');
            }
            InventoryItem::query()->whereKey($data['item_id'])->lockForUpdate()->firstOrFail();
            if (($data['bin_id'] ?? null) !== null) {
                $bin = InventoryBin::query()->with('stockLocation')->whereKey($data['bin_id'])
                    ->where('warehouse_id', $data['warehouse_id'])->lockForUpdate()->firstOrFail();
                if ($bin->stockLocation->custody_type !== 'available') {
                    throw new DomainException('Only available custody can be reserved.');
                }
            }
            StockReservation::query()->where('item_id', $data['item_id'])
                ->where('warehouse_id', $data['warehouse_id'])->lockForUpdate()->get();
            $available = $this->availability->forItem(
                $data['item_id'],
                $data['warehouse_id'],
                $data['bin_id'] ?? null,
            )->availableBase();
            $reserved = min(max(0, $available), $data['requested_quantity_base']);
            $allowPartial = $data['allow_partial'] ?? true;
            if (! $allowPartial && $reserved < $data['requested_quantity_base']) {
                $reserved = 0;
            }
            $status = match (true) {
                $reserved === 0 => ReservationStatus::Rejected,
                $reserved < $data['requested_quantity_base'] => ReservationStatus::PartiallyReserved,
                default => ReservationStatus::Reserved,
            };
            $reservation = StockReservation::query()->create([
                'item_id' => $data['item_id'],
                'warehouse_id' => $data['warehouse_id'],
                'bin_id' => $data['bin_id'] ?? null,
                'lot_id' => $data['lot_id'] ?? null,
                'serial_id' => $data['serial_id'] ?? null,
                'status' => $status,
                'requested_quantity_base' => $data['requested_quantity_base'],
                'reserved_quantity_base' => $reserved,
                'purpose_type' => $data['purpose_type'],
                'purpose_id' => $data['purpose_id'],
                'idempotency_key' => $data['idempotency_key'],
                'expires_at' => $data['expires_at'] ?? null,
            ]);
            $this->outbox->record(
                $reserved === 0 ? 'inventory.stock.reservation_failed.v1' : 'inventory.stock.reserved.v1',
                'stock_reservation',
                (string) $reservation->getKey(),
                [
                    'item_id' => $data['item_id'],
                    'requested_quantity_base' => $data['requested_quantity_base'],
                    'reserved_quantity_base' => $reserved,
                    'purpose_type' => $data['purpose_type'],
                    'purpose_id' => $data['purpose_id'],
                ],
            );

            return $reservation;
        });
    }

    public function consume(StockReservation $reservation, int $quantityBase): StockReservation
    {
        return DB::transaction(function () use ($reservation, $quantityBase): StockReservation {
            $locked = StockReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
            if ($quantityBase <= 0 || ! $locked->status->holdsStock()) {
                throw new DomainException('Only an active reservation can be consumed.');
            }
            $consumed = (int) $locked->consumed_quantity_base + $quantityBase;
            if ($consumed > (int) $locked->reserved_quantity_base) {
                throw new DomainException('Reservation consumption exceeds the reserved quantity.');
            }
            $locked->forceFill([
                'consumed_quantity_base' => $consumed,
                'status' => $consumed === (int) $locked->reserved_quantity_base
                    ? ReservationStatus::Consumed
                    : ReservationStatus::PartiallyConsumed,
            ])->save();

            return $locked;
        });
    }

    public function release(StockReservation $reservation, string $reason): StockReservation
    {
        return DB::transaction(function () use ($reservation, $reason): StockReservation {
            $locked = StockReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->status->holdsStock()) {
                throw new DomainException('Only an active reservation can be released.');
            }
            $locked->forceFill([
                'status' => ReservationStatus::Released,
                'released_at' => now('UTC'),
            ])->save();
            $this->outbox->record('inventory.stock.reservation_released.v1', 'stock_reservation', (string) $locked->getKey(), [
                'reason' => $reason,
                'released_by' => $this->tenant->get()->actorId,
            ]);

            return $locked;
        });
    }
}
