<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProcurementDecisionRequest;
use App\Http\Requests\Api\StorePurchaseRequestRequest;
use App\Http\Resources\Api\V1\PurchaseRequestResource;
use App\Modules\Procurement\Application\PurchaseRequestWorkflow;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class PurchaseRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PurchaseRequest::class);

        return PurchaseRequestResource::collection(
            PurchaseRequest::query()->with('lines')->latest()->cursorPaginate(50),
        );
    }

    public function show(string $purchaseRequest): PurchaseRequestResource
    {
        $model = PurchaseRequest::query()->with('lines')->whereKey($purchaseRequest)->firstOrFail();
        Gate::authorize('view', $model);

        return new PurchaseRequestResource($model);
    }

    public function store(
        StorePurchaseRequestRequest $request,
        PurchaseRequestWorkflow $workflow,
    ): JsonResponse {
        Gate::authorize('create', PurchaseRequest::class);

        return (new PurchaseRequestResource($workflow->create($request->payload())))
            ->response()
            ->setStatusCode(201);
    }

    public function submit(string $purchaseRequest, PurchaseRequestWorkflow $workflow): PurchaseRequestResource
    {
        $model = PurchaseRequest::query()->whereKey($purchaseRequest)->firstOrFail();
        Gate::authorize('update', $model);

        return new PurchaseRequestResource($workflow->submit($model));
    }

    public function approve(
        ProcurementDecisionRequest $request,
        string $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): PurchaseRequestResource {
        $model = PurchaseRequest::query()->whereKey($purchaseRequest)->firstOrFail();
        Gate::authorize('approve', $model);

        return new PurchaseRequestResource($workflow->approve(
            $model,
            (string) $request->validated('approver_role_key'),
            $request->validated('reason'),
        ));
    }

    public function reject(
        ProcurementDecisionRequest $request,
        string $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): PurchaseRequestResource {
        $model = PurchaseRequest::query()->whereKey($purchaseRequest)->firstOrFail();
        Gate::authorize('approve', $model);

        return new PurchaseRequestResource($workflow->reject(
            $model,
            (string) $request->validated('approver_role_key'),
            (string) $request->validated('reason'),
        ));
    }

    public function revise(
        ProcurementDecisionRequest $request,
        string $purchaseRequest,
        PurchaseRequestWorkflow $workflow,
    ): PurchaseRequestResource {
        $model = PurchaseRequest::query()->whereKey($purchaseRequest)->firstOrFail();
        Gate::authorize('update', $model);

        return new PurchaseRequestResource($workflow->requestRevision(
            $model,
            (string) $request->validated('reason'),
        ));
    }
}
