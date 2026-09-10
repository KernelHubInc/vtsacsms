<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\User;
use App\Modules\Inventory\Domain\Models\ReorderPoint;
use App\Modules\Organizations\Domain\PermissionKey;
use Illuminate\Support\Collection;

final readonly class InventoryReportService
{
    public function __construct(
        private AccessibleWarehousesQuery $warehouses,
        private StockAvailabilityQuery $availability,
    ) {}

    /**
     * @return Collection<int, array{
     *   reorder_point_id: string, item_id: string, warehouse_id: string,
     *   on_hand_base: int, reserved_base: int, available_base: int,
     *   minimum_quantity_base: int, reorder_quantity_base: int, target_quantity_base: int
     * }>
     */
    public function reorderAlerts(User $user): Collection
    {
        $warehouseIds = $this->warehouses->for($user, PermissionKey::InventoryView)->select('id');

        return ReorderPoint::query()
            ->where('is_active', true)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get()
            ->map(fn (ReorderPoint $point): array => $this->formatAlert($point))
            ->filter(
                static fn (array $row): bool => $row['available_base'] <= $row['minimum_quantity_base'],
            )
            ->values();
    }

    /**
     * @return array{
     *   reorder_point_id: string, item_id: string, warehouse_id: string,
     *   on_hand_base: int, reserved_base: int, available_base: int,
     *   minimum_quantity_base: int, reorder_quantity_base: int, target_quantity_base: int
     * }
     */
    private function formatAlert(ReorderPoint $point): array
    {
        $stock = $this->availability->forItem(
            (string) $point->item_id,
            (string) $point->warehouse_id,
        );

        return [
            'reorder_point_id' => (string) $point->getKey(),
            'item_id' => (string) $point->item_id,
            'warehouse_id' => (string) $point->warehouse_id,
            ...$stock->toArray(),
            'minimum_quantity_base' => (int) $point->minimum_quantity_base,
            'reorder_quantity_base' => (int) $point->reorder_quantity_base,
            'target_quantity_base' => (int) $point->target_quantity_base,
        ];
    }
}
