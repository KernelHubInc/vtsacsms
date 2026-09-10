<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\Inventory\Application\StockReservationService;
use App\Modules\Inventory\Application\WorkOrderPartsService;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\StockReservation;
use App\Modules\Maintenance\Domain\Models\PartRequirement;
use App\Modules\Maintenance\Domain\Models\WorkOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class MaintenancePartService
{
    public function __construct(
        private StockReservationService $reservations,
        private WorkOrderPartsService $parts,
        private WorkOrderWorkflow $workOrders,
    ) {}

    public function require(
        WorkOrder $workOrder,
        string $itemId,
        string $warehouseId,
        int $quantityBase,
        ?string $preferredBinId = null,
    ): PartRequirement {
        if ($quantityBase <= 0) {
            throw new DomainException('Required part quantity must be positive.');
        }
        InventoryItem::query()->whereKey($itemId)->where('is_active', true)->firstOrFail();

        return PartRequirement::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'preferred_bin_id' => $preferredBinId,
            'required_quantity_base' => $quantityBase,
            'status' => 'required',
        ]);
    }

    public function reserve(
        PartRequirement $requirement,
        string $idempotencyKey,
        bool $allowPartial = true,
    ): PartRequirement {
        return DB::transaction(function () use ($requirement, $idempotencyKey, $allowPartial): PartRequirement {
            $locked = PartRequirement::query()->whereKey($requirement->getKey())->lockForUpdate()->firstOrFail();
            $reservation = $this->reservations->reserve([
                'item_id' => (string) $locked->item_id,
                'warehouse_id' => (string) $locked->warehouse_id,
                'bin_id' => $locked->preferred_bin_id === null ? null : (string) $locked->preferred_bin_id,
                'requested_quantity_base' => $locked->required_quantity_base,
                'purpose_type' => 'maintenance_work_order',
                'purpose_id' => (string) $locked->work_order_id,
                'idempotency_key' => $idempotencyKey,
                'allow_partial' => $allowPartial,
            ]);
            $locked->forceFill([
                'reservation_id' => $reservation->getKey(),
                'reserved_quantity_base' => $reservation->reserved_quantity_base,
                'status' => $reservation->reserved_quantity_base > 0 ? 'reserved' : 'unavailable',
            ])->save();

            return $locked;
        });
    }

    public function issue(
        PartRequirement $requirement,
        string $sourceBinId,
        int $quantityBase,
        string $idempotencyKey,
    ): PartRequirement {
        return DB::transaction(function () use ($requirement, $sourceBinId, $quantityBase, $idempotencyKey): PartRequirement {
            $locked = PartRequirement::query()->whereKey($requirement->getKey())->lockForUpdate()->firstOrFail();
            $reservation = StockReservation::query()->whereKey($locked->reservation_id)->firstOrFail();
            $this->parts->issue($reservation, $sourceBinId, $quantityBase, $idempotencyKey);
            $movement = StockMovement::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            $workOrder = WorkOrder::query()->whereKey($locked->work_order_id)->firstOrFail();
            if ($movement->currency !== $workOrder->currency) {
                throw new DomainException('Issued part cost must use the work-order currency.');
            }
            $issued = $locked->issued_quantity_base + $movement->quantity_base;
            $locked->forceFill([
                'issued_quantity_base' => $issued,
                'status' => $issued >= $locked->required_quantity_base ? 'issued' : 'partially_issued',
            ])->save();
            $this->workOrders->recalculateCost($workOrder);

            return $locked;
        });
    }

    public function returnUnused(
        PartRequirement $requirement,
        string $uomId,
        string $destinationBinId,
        int $quantityBase,
        int $unitCostMinor,
        string $currency,
        string $idempotencyKey,
    ): PartRequirement {
        return DB::transaction(function () use (
            $requirement,
            $uomId,
            $destinationBinId,
            $quantityBase,
            $unitCostMinor,
            $currency,
            $idempotencyKey,
        ): PartRequirement {
            $locked = PartRequirement::query()->whereKey($requirement->getKey())->lockForUpdate()->firstOrFail();
            if ($quantityBase <= 0 || $locked->returned_quantity_base + $quantityBase > $locked->issued_quantity_base) {
                throw new DomainException('Unused return quantity exceeds the issued balance.');
            }
            $workOrder = WorkOrder::query()->whereKey($locked->work_order_id)->firstOrFail();
            $currency = mb_strtoupper($currency);
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $currency !== $workOrder->currency) {
                throw new DomainException('Returned part cost must use the work-order currency.');
            }
            $this->parts->returnUnused(
                (string) $locked->work_order_id,
                (string) $locked->item_id,
                $uomId,
                $destinationBinId,
                $quantityBase,
                $unitCostMinor,
                $currency,
                $idempotencyKey,
            );
            $movement = StockMovement::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            $returned = $locked->returned_quantity_base + $movement->quantity_base;
            $locked->forceFill([
                'returned_quantity_base' => $returned,
                'status' => $returned === $locked->issued_quantity_base ? 'returned' : 'partially_returned',
            ])->save();
            $this->workOrders->recalculateCost($workOrder);

            return $locked;
        });
    }

    public function release(PartRequirement $requirement, string $reason): PartRequirement
    {
        $reservation = StockReservation::query()->whereKey($requirement->reservation_id)->firstOrFail();
        $this->reservations->release($reservation, $reason);
        $requirement->forceFill(['status' => 'released'])->save();

        return $requirement;
    }
}
