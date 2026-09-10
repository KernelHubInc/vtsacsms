<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReceiveTransferRequest;
use App\Http\Requests\Api\StoreTransferRequest;
use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Application\StockTransferWorkflow;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Inventory\Domain\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class StockTransferController extends Controller
{
    public function index(Request $request, AccessibleWarehousesQuery $accessible): JsonResponse
    {
        Gate::authorize('viewAny', StockTransfer::class);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $warehouseIds = $accessible->for($user)->select('id');
        $transfers = StockTransfer::query()
            ->whereIn('source_warehouse_id', clone $warehouseIds)
            ->whereIn('destination_warehouse_id', clone $warehouseIds)
            ->with('lines')
            ->latest()
            ->cursorPaginate(50);

        return response()->json($transfers);
    }

    public function store(StoreTransferRequest $request, StockTransferWorkflow $workflow): JsonResponse
    {
        $source = Warehouse::query()->whereKey($request->validated('source_warehouse_id'))->firstOrFail();
        $destination = Warehouse::query()->whereKey($request->validated('destination_warehouse_id'))->firstOrFail();
        Gate::authorize('update', $source);
        Gate::authorize('update', $destination);
        $transfer = $workflow->create(
            (string) $request->validated('transfer_number'),
            (string) $source->getKey(),
            (string) $destination->getKey(),
            $request->lines(),
        );

        return response()->json(['data' => $this->data($transfer)], 201);
    }

    public function submit(string $transfer, StockTransferWorkflow $workflow): JsonResponse
    {
        return $this->transition($transfer, $workflow, 'submit');
    }

    public function approve(string $transfer, StockTransferWorkflow $workflow): JsonResponse
    {
        return $this->transition($transfer, $workflow, 'approve');
    }

    public function dispatch(string $transfer, StockTransferWorkflow $workflow): JsonResponse
    {
        return $this->transition($transfer, $workflow, 'dispatch');
    }

    public function receive(
        ReceiveTransferRequest $request,
        string $transfer,
        StockTransferWorkflow $workflow,
    ): JsonResponse {
        $model = StockTransfer::query()->whereKey($transfer)->firstOrFail();
        Gate::authorize('update', $model);

        return response()->json(['data' => $this->data($workflow->receive($model, $request->quantitiesByLine()))]);
    }

    private function transition(
        string $id,
        StockTransferWorkflow $workflow,
        string $transition,
    ): JsonResponse {
        $model = StockTransfer::query()->whereKey($id)->firstOrFail();
        Gate::authorize('update', $model);
        $updated = match ($transition) {
            'submit' => $workflow->submit($model),
            'approve' => $workflow->approve($model),
            'dispatch' => $workflow->dispatch($model),
            default => $model,
        };

        return response()->json(['data' => $this->data($updated)]);
    }

    /** @return array<string, mixed> */
    private function data(StockTransfer $transfer): array
    {
        return [
            'id' => (string) $transfer->getKey(),
            'transfer_number' => (string) $transfer->transfer_number,
            'source_warehouse_id' => (string) $transfer->source_warehouse_id,
            'destination_warehouse_id' => (string) $transfer->destination_warehouse_id,
            'status' => $transfer->status->value,
            'lines' => $transfer->relationLoaded('lines')
                ? $transfer->lines->map(static fn ($line): array => [
                    'id' => (string) $line->getKey(),
                    'item_id' => (string) $line->item_id,
                    'requested_quantity_base' => (int) $line->requested_quantity_base,
                    'dispatched_quantity_base' => (int) $line->dispatched_quantity_base,
                    'received_quantity_base' => (int) $line->received_quantity_base,
                ])
                : [],
        ];
    }
}
