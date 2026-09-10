<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreatePurchaseOrderRequest;
use App\Http\Requests\Api\CreateRfqRequest;
use App\Http\Requests\Api\PrepareQuotationComparisonRequest;
use App\Http\Requests\Api\ProcurementDecisionRequest;
use App\Http\Requests\Api\StoreSupplierQuotationRequest;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Modules\Procurement\Application\SourcingWorkflow;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Procurement\Domain\Models\QuotationComparison;
use App\Modules\Procurement\Domain\Models\RequestForQuotation;
use App\Modules\Procurement\Domain\Models\Supplier;
use App\Modules\Procurement\Domain\Models\SupplierQuotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class SourcingController extends Controller
{
    public function createRfq(
        CreateRfqRequest $request,
        string $purchaseRequest,
        SourcingWorkflow $workflow,
    ): JsonResponse {
        $model = PurchaseRequest::query()->whereKey($purchaseRequest)->firstOrFail();
        Gate::authorize('update', $model);
        $rfq = $workflow->createRfq(
            $model,
            (string) $request->validated('rfq_number'),
            $request->validated('supplier_ids'),
            (string) $request->validated('closes_at'),
            $request->validated('instructions'),
        );

        return response()->json(['data' => $this->rfqData($rfq)], 201);
    }

    public function recordQuotation(
        StoreSupplierQuotationRequest $request,
        string $rfq,
        SourcingWorkflow $workflow,
    ): JsonResponse {
        $model = RequestForQuotation::query()->whereKey($rfq)->firstOrFail();
        $purchaseRequest = PurchaseRequest::query()->whereKey($model->purchase_request_id)->firstOrFail();
        Gate::authorize('update', $purchaseRequest);
        $supplier = Supplier::query()->whereKey($request->validated('supplier_id'))->firstOrFail();
        $quotation = $workflow->recordQuotation($model, $supplier, $request->quotationPayload());

        return response()->json(['data' => [
            'id' => (string) $quotation->getKey(),
            'rfq_id' => (string) $quotation->rfq_id,
            'supplier_id' => (string) $quotation->supplier_id,
            'quotation_reference' => (string) $quotation->quotation_reference,
            'currency' => (string) $quotation->currency,
            'total_minor' => (int) $quotation->total_minor,
        ]], 201);
    }

    public function prepareComparison(
        PrepareQuotationComparisonRequest $request,
        string $rfq,
        SourcingWorkflow $workflow,
    ): JsonResponse {
        $model = RequestForQuotation::query()->whereKey($rfq)->firstOrFail();
        $purchaseRequest = PurchaseRequest::query()->whereKey($model->purchase_request_id)->firstOrFail();
        Gate::authorize('update', $purchaseRequest);
        $selected = SupplierQuotation::query()
            ->whereKey($request->validated('selected_quotation_id'))
            ->firstOrFail();
        $comparison = $workflow->prepareComparison(
            $model,
            $selected,
            (string) $request->validated('selection_reason'),
        );

        return response()->json(['data' => $this->comparisonData($comparison)], 201);
    }

    public function approveComparison(string $comparison, SourcingWorkflow $workflow): JsonResponse
    {
        $model = QuotationComparison::query()->whereKey($comparison)->firstOrFail();
        $rfq = RequestForQuotation::query()->whereKey($model->rfq_id)->firstOrFail();
        $purchaseRequest = PurchaseRequest::query()->whereKey($rfq->purchase_request_id)->firstOrFail();
        Gate::authorize('approve', $purchaseRequest);

        return response()->json(['data' => $this->comparisonData($workflow->approveComparison($model))]);
    }

    public function createPurchaseOrder(
        CreatePurchaseOrderRequest $request,
        string $comparison,
        SourcingWorkflow $workflow,
    ): JsonResponse {
        $model = QuotationComparison::query()->whereKey($comparison)->firstOrFail();
        $rfq = RequestForQuotation::query()->whereKey($model->rfq_id)->firstOrFail();
        $purchaseRequest = PurchaseRequest::query()->whereKey($rfq->purchase_request_id)->firstOrFail();
        Gate::authorize('update', $purchaseRequest);
        $order = $workflow->createPurchaseOrder(
            $model,
            (string) $request->validated('po_number'),
            $request->validated('delivery_warehouse_id'),
            $request->validated('terms'),
        );

        return (new PurchaseOrderResource($order))->response()->setStatusCode(201);
    }

    public function indexOrders(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        return PurchaseOrderResource::collection(
            PurchaseOrder::query()->with('lines')->latest()->cursorPaginate(50),
        );
    }

    public function showOrder(string $purchaseOrder): PurchaseOrderResource
    {
        $order = PurchaseOrder::query()->with('lines')->whereKey($purchaseOrder)->firstOrFail();
        Gate::authorize('view', $order);

        return new PurchaseOrderResource($order);
    }

    public function submitOrder(string $purchaseOrder, SourcingWorkflow $workflow): PurchaseOrderResource
    {
        $order = PurchaseOrder::query()->whereKey($purchaseOrder)->firstOrFail();
        Gate::authorize('update', $order);

        return new PurchaseOrderResource($workflow->submitPurchaseOrder($order));
    }

    public function approveOrder(
        ProcurementDecisionRequest $request,
        string $purchaseOrder,
        SourcingWorkflow $workflow,
    ): PurchaseOrderResource {
        $order = PurchaseOrder::query()->whereKey($purchaseOrder)->firstOrFail();
        Gate::authorize('approve', $order);

        return new PurchaseOrderResource($workflow->approvePurchaseOrder(
            $order,
            (string) $request->validated('approver_role_key'),
        ));
    }

    public function issueOrder(string $purchaseOrder, SourcingWorkflow $workflow): PurchaseOrderResource
    {
        $order = PurchaseOrder::query()->whereKey($purchaseOrder)->firstOrFail();
        Gate::authorize('approve', $order);

        return new PurchaseOrderResource($workflow->issuePurchaseOrder($order));
    }

    /** @return array<string, mixed> */
    private function rfqData(RequestForQuotation $rfq): array
    {
        return [
            'id' => (string) $rfq->getKey(),
            'purchase_request_id' => (string) $rfq->purchase_request_id,
            'rfq_number' => (string) $rfq->rfq_number,
            'status' => (string) $rfq->status,
            'closes_at' => $rfq->closes_at->utc()->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function comparisonData(QuotationComparison $comparison): array
    {
        return [
            'id' => (string) $comparison->getKey(),
            'rfq_id' => (string) $comparison->rfq_id,
            'selected_quotation_id' => $comparison->selected_quotation_id,
            'status' => (string) $comparison->status,
            'selection_reason' => $comparison->selection_reason,
            'comparison_snapshot' => $comparison->comparison_snapshot,
        ];
    }
}
