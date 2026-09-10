<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MaintenanceAssignmentRequest;
use App\Http\Requests\Api\MaintenanceChecklistRequest;
use App\Http\Requests\Api\MaintenanceResolutionRequest;
use App\Http\Requests\Api\MaintenanceTimeRequest;
use App\Http\Requests\Api\MaintenanceTransitionRequest;
use App\Http\Requests\Api\StoreMaintenanceServiceRequest;
use App\Http\Requests\Api\StoreMaintenanceWorkOrderRequest;
use App\Http\Resources\Api\V1\MaintenanceIncidentResource;
use App\Http\Resources\Api\V1\MaintenanceWorkOrderResource;
use App\Models\User;
use App\Modules\Maintenance\Application\AccessibleMaintenanceSitesQuery;
use App\Modules\Maintenance\Application\MaintenanceAuthorization;
use App\Modules\Maintenance\Application\MaintenanceDashboardService;
use App\Modules\Maintenance\Application\MaintenanceIntakeService;
use App\Modules\Maintenance\Application\WorkOrderWorkflow;
use App\Modules\Maintenance\Domain\Models\Incident;
use App\Modules\Maintenance\Domain\Models\ServiceRequest;
use App\Modules\Maintenance\Domain\Models\WorkOrder;
use App\Modules\Maintenance\Domain\Models\WorkOrderChecklistItem;
use App\Modules\Maintenance\Domain\WorkOrderState;
use App\Modules\Organizations\Domain\PermissionKey;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class MaintenanceController extends Controller
{
    public function workOrders(Request $request, AccessibleMaintenanceSitesQuery $sites): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WorkOrder::class);
        $user = $this->user($request);
        $siteIds = $sites->for($user)->pluck('id');

        return MaintenanceWorkOrderResource::collection(
            WorkOrder::query()
                ->whereIn('site_id', $siteIds)
                ->with(['assignments', 'checklistItems', 'partRequirements'])
                ->latest()
                ->cursorPaginate(50),
        );
    }

    public function showWorkOrder(string $workOrder): MaintenanceWorkOrderResource
    {
        $model = WorkOrder::query()->with([
            'assignments',
            'checklistItems',
            'partRequirements',
            'timeEntries',
            'transitions',
            'attachments',
        ])->whereKey($workOrder)->firstOrFail();
        Gate::authorize('view', $model);

        return new MaintenanceWorkOrderResource($model);
    }

    public function storeWorkOrder(
        StoreMaintenanceWorkOrderRequest $request,
        WorkOrderWorkflow $workflow,
        MaintenanceAuthorization $authorization,
    ): JsonResponse {
        Gate::authorize('create', WorkOrder::class);
        if (! $authorization->allowsSite(
            $this->user($request),
            PermissionKey::MaintenanceDispatch,
            (string) $request->validated('site_id'),
        )) {
            abort(403);
        }

        return (new MaintenanceWorkOrderResource($workflow->create($request->payload())))
            ->response()
            ->setStatusCode(201);
    }

    public function transition(
        MaintenanceTransitionRequest $request,
        string $workOrder,
        WorkOrderWorkflow $workflow,
    ): MaintenanceWorkOrderResource {
        $model = WorkOrder::query()->whereKey($workOrder)->firstOrFail();
        $target = $request->target();
        Gate::authorize($this->transitionAbility($target), $model);

        return new MaintenanceWorkOrderResource($workflow->transition(
            $model,
            $target,
            (string) $request->validated('reason_code'),
            $request->validated('notes'),
        ));
    }

    public function assign(
        MaintenanceAssignmentRequest $request,
        string $workOrder,
        WorkOrderWorkflow $workflow,
    ): MaintenanceWorkOrderResource {
        $model = WorkOrder::query()->whereKey($workOrder)->firstOrFail();
        Gate::authorize('dispatch', $model);
        $workflow->assign(
            $model,
            $request->validated('technician_user_id'),
            $request->validated('vendor_organization_id'),
        );

        return new MaintenanceWorkOrderResource($model->refresh()->load('assignments'));
    }

    public function checklist(
        MaintenanceChecklistRequest $request,
        string $workOrder,
        string $item,
        WorkOrderWorkflow $workflow,
    ): JsonResponse {
        $model = WorkOrder::query()->whereKey($workOrder)->firstOrFail();
        Gate::authorize('perform', $model);
        $checklistItem = WorkOrderChecklistItem::query()
            ->where('work_order_id', $model->getKey())
            ->whereKey($item)
            ->firstOrFail();
        $result = $workflow->completeChecklistItem(
            $checklistItem,
            (string) $request->validated('result'),
            $request->validated('notes'),
        );

        return response()->json(['data' => $result]);
    }

    public function time(
        MaintenanceTimeRequest $request,
        string $workOrder,
        WorkOrderWorkflow $workflow,
    ): JsonResponse {
        $model = WorkOrder::query()->whereKey($workOrder)->firstOrFail();
        Gate::authorize('perform', $model);
        $entry = $workflow->recordTime(
            $model,
            (string) $request->validated('type'),
            (int) $request->validated('duration_seconds'),
            (int) $request->validated('rate_minor_per_hour'),
            (string) $request->validated('currency'),
            $request->validated('started_at') === null
                ? null
                : CarbonImmutable::parse((string) $request->validated('started_at'))->utc(),
            $request->validated('notes'),
        );

        return response()->json(['data' => $entry], 201);
    }

    public function resolution(
        MaintenanceResolutionRequest $request,
        string $workOrder,
        WorkOrderWorkflow $workflow,
    ): MaintenanceWorkOrderResource {
        $model = WorkOrder::query()->whereKey($workOrder)->firstOrFail();
        Gate::authorize('perform', $model);

        return new MaintenanceWorkOrderResource($workflow->recordResolution(
            $model,
            (string) $request->validated('work_performed'),
            (string) $request->validated('resolution_code_id'),
            $request->validated('failure_code_id'),
            $request->validated('root_cause_code_id'),
            $request->validated('diagnosis'),
        ));
    }

    public function reopen(Request $request, string $workOrder, WorkOrderWorkflow $workflow): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $model = WorkOrder::query()->whereKey($workOrder)->firstOrFail();
        Gate::authorize('dispatch', $model);

        return (new MaintenanceWorkOrderResource($workflow->reopen($model, (string) $request->input('reason'))))
            ->response()
            ->setStatusCode(201);
    }

    public function serviceRequests(
        Request $request,
        AccessibleMaintenanceSitesQuery $sites,
    ): JsonResponse {
        $siteIds = $sites->for($this->user($request))->pluck('id');

        return response()->json(['data' => ServiceRequest::query()
            ->whereIn('site_id', $siteIds)->latest()->cursorPaginate(50)]);
    }

    public function storeServiceRequest(
        StoreMaintenanceServiceRequest $request,
        MaintenanceIntakeService $intake,
        MaintenanceAuthorization $authorization,
    ): JsonResponse {
        if (! $authorization->allowsSite(
            $this->user($request),
            PermissionKey::MaintenanceDispatch,
            (string) $request->validated('site_id'),
        )) {
            abort(403);
        }

        return response()->json(['data' => $intake->submitServiceRequest($request->payload())], 201);
    }

    public function incidents(Request $request, AccessibleMaintenanceSitesQuery $sites): AnonymousResourceCollection
    {
        $siteIds = $sites->for($this->user($request))->pluck('id');

        return MaintenanceIncidentResource::collection(
            Incident::query()->whereIn('site_id', $siteIds)->latest('last_observed_at')->cursorPaginate(50),
        );
    }

    public function dashboard(Request $request, MaintenanceDashboardService $dashboard): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],
        ]);
        $to = $request->filled('to')
            ? CarbonImmutable::parse((string) $request->query('to'))->utc()
            : CarbonImmutable::now('UTC');
        $from = $request->filled('from')
            ? CarbonImmutable::parse((string) $request->query('from'))->utc()
            : $to->subDays(30);

        return response()->json(['data' => $dashboard->summary($this->user($request), $from, $to)]);
    }

    private function transitionAbility(WorkOrderState $target): string
    {
        return match ($target) {
            WorkOrderState::InProgress,
            WorkOrderState::OnHold,
            WorkOrderState::AwaitingParts,
            WorkOrderState::AwaitingAccess,
            WorkOrderState::AwaitingExternal,
            WorkOrderState::AwaitingSafetyClearance,
            WorkOrderState::Completed,
            WorkOrderState::VerificationRequired => 'perform',
            WorkOrderState::Verified,
            WorkOrderState::Closed => 'verify',
            default => 'dispatch',
        };
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
