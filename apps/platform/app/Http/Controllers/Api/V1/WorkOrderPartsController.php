<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IssueWorkOrderPartsRequest;
use App\Http\Requests\Api\ReturnWorkOrderPartsRequest;
use App\Modules\Inventory\Application\WorkOrderPartsService;
use App\Modules\Inventory\Domain\Models\InventoryBin;
use App\Modules\Inventory\Domain\Models\StockReservation;
use App\Modules\Inventory\Domain\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class WorkOrderPartsController extends Controller
{
    public function issue(
        IssueWorkOrderPartsRequest $request,
        WorkOrderPartsService $parts,
    ): JsonResponse {
        $reservation = StockReservation::query()->whereKey($request->validated('reservation_id'))->firstOrFail();
        $warehouse = Warehouse::query()->whereKey($reservation->warehouse_id)->firstOrFail();
        Gate::authorize('update', $warehouse);
        $parts->issue(
            $reservation,
            (string) $request->validated('source_bin_id'),
            (int) $request->validated('quantity_base'),
            (string) $request->validated('idempotency_key'),
        );

        return response()->json(['data' => ['status' => 'issued']]);
    }

    public function returnUnused(
        ReturnWorkOrderPartsRequest $request,
        WorkOrderPartsService $parts,
    ): JsonResponse {
        $bin = InventoryBin::query()->whereKey($request->validated('destination_bin_id'))->firstOrFail();
        $warehouse = Warehouse::query()->whereKey($bin->warehouse_id)->firstOrFail();
        Gate::authorize('update', $warehouse);
        $parts->returnUnused(
            (string) $request->validated('work_order_id'),
            (string) $request->validated('item_id'),
            (string) $request->validated('uom_id'),
            (string) $request->validated('destination_bin_id'),
            (int) $request->validated('quantity_base'),
            (int) $request->validated('unit_cost_minor'),
            (string) $request->validated('currency'),
            (string) $request->validated('idempotency_key'),
            [
                'lot_id' => $request->validated('lot_id'),
                'serial_id' => $request->validated('serial_id'),
            ],
        );

        return response()->json(['data' => ['status' => 'returned']]);
    }
}
