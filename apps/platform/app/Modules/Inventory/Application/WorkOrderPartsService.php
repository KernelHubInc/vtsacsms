<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\StockReservation;
use App\Modules\Inventory\Domain\MovementType;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class WorkOrderPartsService
{
    public function __construct(
        private StockLedger $ledger,
        private StockReservationService $reservations,
        private MovingAverageValuation $valuation,
    ) {}

    public function issue(
        StockReservation $reservation,
        string $sourceBinId,
        int $quantityBase,
        string $idempotencyKey,
    ): void {
        DB::transaction(function () use ($reservation, $sourceBinId, $quantityBase, $idempotencyKey): void {
            if ($reservation->purpose_type !== 'maintenance_work_order') {
                throw new DomainException('Only work-order reservations can use the parts issue workflow.');
            }
            $item = $reservation->item_id;
            $inventoryItem = InventoryItem::query()->whereKey($item)->firstOrFail();
            $this->ledger->post([
                'item_id' => (string) $item,
                'uom_id' => (string) $inventoryItem->base_uom_id,
                'from_bin_id' => $sourceBinId,
                'lot_id' => $reservation->lot_id,
                'serial_id' => $reservation->serial_id,
                'movement_type' => MovementType::WorkOrderIssue,
                'quantity_base' => $quantityBase,
                'currency' => (string) $inventoryItem->currency,
                'unit_cost_minor' => $this->valuation->unitCostMinor($inventoryItem),
                'reference_type' => 'maintenance_work_order',
                'reference_id' => (string) $reservation->purpose_id,
                'reason_code' => 'work_order_issue',
                'idempotency_key' => $idempotencyKey,
                'reservation_id' => (string) $reservation->getKey(),
            ]);
            $this->reservations->consume($reservation, $quantityBase);
        });
    }

    /** @param array{lot_id?: string|null, serial_id?: string|null} $tracking */
    public function returnUnused(
        string $workOrderId,
        string $itemId,
        string $uomId,
        string $destinationBinId,
        int $quantityBase,
        int $unitCostMinor,
        string $currency,
        string $idempotencyKey,
        array $tracking = [],
    ): void {
        $this->ledger->post([
            'item_id' => $itemId,
            'uom_id' => $uomId,
            'to_bin_id' => $destinationBinId,
            'lot_id' => $tracking['lot_id'] ?? null,
            'serial_id' => $tracking['serial_id'] ?? null,
            'movement_type' => MovementType::WorkOrderReturn,
            'quantity_base' => $quantityBase,
            'currency' => $currency,
            'unit_cost_minor' => $unitCostMinor,
            'reference_type' => 'maintenance_work_order',
            'reference_id' => $workOrderId,
            'reason_code' => 'unused_parts_return',
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
