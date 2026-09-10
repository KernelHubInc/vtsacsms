<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAdjustmentRequest;
use App\Models\User;
use App\Modules\Inventory\Application\AccessibleWarehousesQuery;
use App\Modules\Inventory\Application\InventoryAdjustmentWorkflow;
use App\Modules\Inventory\Domain\Models\AdjustmentRequest;
use App\Modules\Inventory\Domain\Models\Warehouse;
use App\Modules\Organizations\Domain\PermissionKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class InventoryAdjustmentController extends Controller
{
    public function index(Request $request, AccessibleWarehousesQuery $accessible): JsonResponse
    {
        Gate::authorize('viewAny', AdjustmentRequest::class);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $adjustments = AdjustmentRequest::query()
            ->whereIn('warehouse_id', $accessible->for($user, PermissionKey::InventoryView)->select('id'))
            ->with('lines')
            ->latest()
            ->cursorPaginate(50);

        return response()->json($adjustments);
    }

    public function store(
        StoreAdjustmentRequest $request,
        InventoryAdjustmentWorkflow $workflow,
    ): JsonResponse {
        $warehouse = Warehouse::query()->whereKey($request->validated('warehouse_id'))->firstOrFail();
        Gate::authorize('adjust', $warehouse);
        $adjustment = $workflow->request(
            (string) $request->validated('adjustment_number'),
            (string) $warehouse->getKey(),
            (string) $request->validated('reason_code'),
            (string) $request->validated('reason_notes'),
            $request->validated('lines'),
            $request->validated('source_type'),
            $request->validated('source_id'),
        );

        return response()->json(['data' => $this->data($adjustment)], 201);
    }

    public function approve(string $adjustment, InventoryAdjustmentWorkflow $workflow): JsonResponse
    {
        return $this->transition($adjustment, $workflow, 'approve');
    }

    public function post(string $adjustment, InventoryAdjustmentWorkflow $workflow): JsonResponse
    {
        return $this->transition($adjustment, $workflow, 'post');
    }

    private function transition(
        string $id,
        InventoryAdjustmentWorkflow $workflow,
        string $transition,
    ): JsonResponse {
        $model = AdjustmentRequest::query()->whereKey($id)->firstOrFail();
        Gate::authorize('update', $model);
        $updated = $transition === 'approve' ? $workflow->approve($model) : $workflow->post($model);

        return response()->json(['data' => $this->data($updated)]);
    }

    /** @return array<string, mixed> */
    private function data(AdjustmentRequest $request): array
    {
        return [
            'id' => (string) $request->getKey(),
            'adjustment_number' => (string) $request->adjustment_number,
            'warehouse_id' => (string) $request->warehouse_id,
            'status' => (string) $request->status,
            'reason_code' => (string) $request->reason_code,
        ];
    }
}
