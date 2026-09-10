<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\InventoryImportRequest;
use App\Http\Resources\Api\V1\InventoryItemResource;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Http\Resources\Api\V1\WarehouseResource;
use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Application\InventoryCatalogCsvService;
use App\Modules\Inventory\Application\InventoryReportService;
use App\Modules\Inventory\Application\StockAvailabilityQuery;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InventoryCatalogController extends Controller
{
    public function items(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', InventoryItem::class);

        return InventoryItemResource::collection(
            InventoryItem::query()->with(['category', 'baseUnit'])->orderBy('sku')->cursorPaginate(100),
        );
    }

    public function warehouses(
        Request $request,
        AccessibleWarehousesQuery $accessible,
    ): AnonymousResourceCollection {
        Gate::authorize('viewAny', Warehouse::class);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return WarehouseResource::collection($accessible->for($user)->orderBy('code')->cursorPaginate(100));
    }

    public function availability(
        Request $request,
        string $item,
        StockAvailabilityQuery $availability,
    ): JsonResponse {
        $inventoryItem = InventoryItem::query()->whereKey($item)->firstOrFail();
        Gate::authorize('view', $inventoryItem);
        $warehouseId = $request->string('warehouse_id')->toString() ?: null;
        $binId = $request->string('bin_id')->toString() ?: null;
        if ($warehouseId !== null) {
            $warehouse = Warehouse::query()->whereKey($warehouseId)->firstOrFail();
            Gate::authorize('view', $warehouse);
        }

        return response()->json(['data' => [
            'item_id' => (string) $inventoryItem->getKey(),
            'warehouse_id' => $warehouseId,
            'bin_id' => $binId,
            ...$availability->forItem((string) $inventoryItem->getKey(), $warehouseId, $binId)->toArray(),
        ]]);
    }

    public function movements(
        Request $request,
        AccessibleWarehousesQuery $accessible,
    ): AnonymousResourceCollection {
        Gate::authorize('viewAny', Warehouse::class);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $warehouseIds = $accessible->for($user)->select('id');
        $binIds = InventoryBin::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->select('id');

        return StockMovementResource::collection(
            StockMovement::query()
                ->where(function ($query) use ($binIds): void {
                    $query->whereIn('from_bin_id', clone $binIds)->orWhereIn('to_bin_id', clone $binIds);
                })
                ->latest('occurred_at')
                ->cursorPaginate(100),
        );
    }

    public function reorderAlerts(Request $request, InventoryReportService $reports): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => $reports->reorderAlerts($user)]);
    }

    public function importTemplate(InventoryCatalogCsvService $csv): StreamedResponse
    {
        Gate::authorize('create', InventoryItem::class);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv->template();
        }, 'inventory-items-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function import(InventoryImportRequest $request, InventoryCatalogCsvService $csv): JsonResponse
    {
        Gate::authorize('create', InventoryItem::class);
        $content = $request->file('file')?->get();

        return response()->json(['data' => $csv->import(is_string($content) ? $content : '')], 202);
    }

    public function export(InventoryCatalogCsvService $csv): StreamedResponse
    {
        Gate::authorize('viewAny', InventoryItem::class);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv->export();
        }, 'inventory-items.csv', ['Content-Type' => 'text/csv']);
    }
}
