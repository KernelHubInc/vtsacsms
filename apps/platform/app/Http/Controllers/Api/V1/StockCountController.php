<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCountPlanRequest;
use App\Http\Requests\Api\SubmitCountSheetRequest;
use App\Modules\Inventory\Application\StockCountWorkflow;
use App\Modules\Inventory\Domain\Models\CountPlan;
use App\Modules\Inventory\Domain\Models\CountSheet;
use App\Modules\Inventory\Domain\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class StockCountController extends Controller
{
    public function store(StoreCountPlanRequest $request, StockCountWorkflow $workflow): JsonResponse
    {
        $warehouse = Warehouse::query()->whereKey($request->validated('warehouse_id'))->firstOrFail();
        Gate::authorize('update', $warehouse);
        $plan = $workflow->create(
            (string) $request->validated('plan_number'),
            (string) $request->validated('count_type'),
            (string) $warehouse->getKey(),
            (string) $request->validated('scheduled_for'),
            (bool) $request->validated('blind_count'),
            $request->sheets(),
        );

        return response()->json(['data' => $this->data($plan)], 201);
    }

    public function start(string $countPlan, StockCountWorkflow $workflow): JsonResponse
    {
        $plan = $this->authorizePlan($countPlan, 'update');

        return response()->json(['data' => $this->data($workflow->start($plan))]);
    }

    public function submitSheet(
        SubmitCountSheetRequest $request,
        string $countSheet,
        StockCountWorkflow $workflow,
    ): JsonResponse {
        $sheet = CountSheet::query()->whereKey($countSheet)->firstOrFail();
        $plan = $this->authorizePlan((string) $sheet->count_plan_id, 'update');
        $updated = $workflow->submitSheet(
            $sheet,
            $request->observations(),
            (int) $request->validated('recount_threshold_base', 0),
        );

        return response()->json(['data' => [
            'id' => (string) $updated->getKey(),
            'count_plan_id' => (string) $plan->getKey(),
            'status' => (string) $updated->status,
            'round' => (int) $updated->round,
        ]]);
    }

    public function recount(string $countSheet, StockCountWorkflow $workflow): JsonResponse
    {
        $sheet = CountSheet::query()->whereKey($countSheet)->firstOrFail();
        $this->authorizePlan((string) $sheet->count_plan_id, 'update');
        $recount = $workflow->createRecount($sheet);

        return response()->json(['data' => [
            'id' => (string) $recount->getKey(),
            'count_plan_id' => (string) $recount->count_plan_id,
            'round' => (int) $recount->round,
            'status' => (string) $recount->status,
        ]], 201);
    }

    public function approve(string $countPlan, StockCountWorkflow $workflow): JsonResponse
    {
        $plan = $this->authorizePlan($countPlan, 'adjust');

        return response()->json(['data' => $this->data($workflow->approve($plan))]);
    }

    public function post(string $countPlan, StockCountWorkflow $workflow): JsonResponse
    {
        $plan = $this->authorizePlan($countPlan, 'adjust');

        return response()->json(['data' => $this->data($workflow->post($plan))]);
    }

    private function authorizePlan(string $id, string $ability): CountPlan
    {
        $plan = CountPlan::query()->whereKey($id)->firstOrFail();
        $warehouse = Warehouse::query()->whereKey($plan->warehouse_id)->firstOrFail();
        Gate::authorize($ability, $warehouse);

        return $plan;
    }

    /** @return array<string, mixed> */
    private function data(CountPlan $plan): array
    {
        return [
            'id' => (string) $plan->getKey(),
            'plan_number' => (string) $plan->plan_number,
            'count_type' => (string) $plan->count_type,
            'warehouse_id' => (string) $plan->warehouse_id,
            'status' => $plan->status->value,
            'blind_count' => (bool) $plan->blind_count,
            'scheduled_for' => $plan->scheduled_for->format('Y-m-d'),
        ];
    }
}
