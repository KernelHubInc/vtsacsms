<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReserveStockRequest;
use App\Modules\Inventory\Application\StockReservationService;
use App\Modules\Inventory\Domain\Models\StockReservation;
use App\Modules\Inventory\Domain\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class StockReservationController extends Controller
{
    public function reserve(
        ReserveStockRequest $request,
        StockReservationService $reservations,
    ): JsonResponse {
        $warehouse = Warehouse::query()->whereKey($request->validated('warehouse_id'))->firstOrFail();
        Gate::authorize('update', $warehouse);
        $reservation = $reservations->reserve($request->payload());

        return response()->json(['data' => $this->data($reservation)], 201);
    }

    public function release(
        string $reservation,
        StockReservationService $reservations,
    ): JsonResponse {
        $model = StockReservation::query()->whereKey($reservation)->firstOrFail();
        $warehouse = Warehouse::query()->whereKey($model->warehouse_id)->firstOrFail();
        Gate::authorize('update', $warehouse);

        return response()->json(['data' => $this->data($reservations->release($model, 'operator_release'))]);
    }

    /** @return array<string, mixed> */
    private function data(StockReservation $reservation): array
    {
        return [
            'id' => (string) $reservation->getKey(),
            'item_id' => (string) $reservation->item_id,
            'warehouse_id' => (string) $reservation->warehouse_id,
            'bin_id' => $reservation->bin_id,
            'status' => $reservation->status->value,
            'requested_quantity_base' => (int) $reservation->requested_quantity_base,
            'reserved_quantity_base' => (int) $reservation->reserved_quantity_base,
            'consumed_quantity_base' => (int) $reservation->consumed_quantity_base,
            'purpose_type' => (string) $reservation->purpose_type,
            'purpose_id' => (string) $reservation->purpose_id,
        ];
    }
}
