<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\ReservationStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class StockAvailabilityQuery
{
    public function __construct(private CurrentTenant $tenant) {}

    public function forItem(string $itemId, ?string $warehouseId = null, ?string $binId = null): StockAvailability
    {
        $tenantId = $this->tenant->get()->tenantId;
        $bins = $this->binIds($warehouseId, $binId);

        $incoming = DB::table('inventory_stock_movements')
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->when($bins !== null, fn (Builder $query): Builder => $query->whereIn('to_bin_id', $bins))
            ->when($bins === null, fn (Builder $query): Builder => $query->whereNotNull('to_bin_id'))
            ->sum('quantity_base');
        $outgoing = DB::table('inventory_stock_movements')
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->when($bins !== null, fn (Builder $query): Builder => $query->whereIn('from_bin_id', $bins))
            ->when($bins === null, fn (Builder $query): Builder => $query->whereNotNull('from_bin_id'))
            ->sum('quantity_base');

        $eligibleBins = InventoryBin::query()
            ->whereHas('stockLocation', fn ($query) => $query->where('custody_type', 'available'))
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->when($binId !== null, fn ($query) => $query->whereKey($binId))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
        $eligibleIncoming = DB::table('inventory_stock_movements')
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->whereIn('to_bin_id', $eligibleBins)
            ->sum('quantity_base');
        $eligibleOutgoing = DB::table('inventory_stock_movements')
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->whereIn('from_bin_id', $eligibleBins)
            ->sum('quantity_base');

        $reserved = DB::table('inventory_reservations')
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->when($warehouseId !== null, fn (Builder $query): Builder => $query->where('warehouse_id', $warehouseId))
            ->when($binId !== null, fn (Builder $query): Builder => $query->where('bin_id', $binId))
            ->whereIn('status', [
                ReservationStatus::PartiallyReserved->value,
                ReservationStatus::Reserved->value,
                ReservationStatus::PartiallyConsumed->value,
            ])
            ->selectRaw('COALESCE(SUM(reserved_quantity_base - consumed_quantity_base), 0) AS aggregate')
            ->value('aggregate');

        return new StockAvailability(
            (int) $incoming - (int) $outgoing,
            (int) $reserved,
            (int) $eligibleIncoming - (int) $eligibleOutgoing,
        );
    }

    /** @return list<string>|null */
    private function binIds(?string $warehouseId, ?string $binId): ?array
    {
        if ($binId !== null) {
            InventoryBin::query()->whereKey($binId)->firstOrFail();

            return [$binId];
        }

        if ($warehouseId === null) {
            return null;
        }

        return array_values(InventoryBin::query()
            ->where('warehouse_id', $warehouseId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }
}
