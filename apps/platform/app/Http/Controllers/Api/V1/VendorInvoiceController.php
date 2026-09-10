<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExportVendorInvoiceRequest;
use App\Http\Requests\Api\MatchVendorInvoiceRequest;
use App\Http\Requests\Api\StoreVendorInvoiceRequest;
use App\Http\Resources\Api\V1\VendorInvoiceResource;
use App\Modules\Procurement\Application\VendorInvoiceWorkflow;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\VendorInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class VendorInvoiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', VendorInvoice::class);

        return VendorInvoiceResource::collection(
            VendorInvoice::query()->with('lines')->latest()->cursorPaginate(50),
        );
    }

    public function store(
        StoreVendorInvoiceRequest $request,
        string $purchaseOrder,
        VendorInvoiceWorkflow $workflow,
    ): JsonResponse {
        Gate::authorize('create', VendorInvoice::class);
        $order = PurchaseOrder::query()->whereKey($purchaseOrder)->firstOrFail();
        Gate::authorize('view', $order);

        return (new VendorInvoiceResource($workflow->create($order, $request->payload())))
            ->response()
            ->setStatusCode(201);
    }

    public function match(
        MatchVendorInvoiceRequest $request,
        string $vendorInvoice,
        VendorInvoiceWorkflow $workflow,
    ): JsonResponse {
        $invoice = VendorInvoice::query()->whereKey($vendorInvoice)->firstOrFail();
        Gate::authorize('approve', $invoice);
        $match = $workflow->match($invoice, (int) $request->validated('amount_tolerance_minor', 0));

        return response()->json(['data' => [
            'id' => (string) $match->getKey(),
            'vendor_invoice_id' => (string) $match->vendor_invoice_id,
            'status' => (string) $match->status,
            'amount_variance_minor' => (int) $match->amount_variance_minor,
            'evidence_snapshot' => $match->evidence_snapshot,
        ]]);
    }

    public function approve(string $vendorInvoice, VendorInvoiceWorkflow $workflow): VendorInvoiceResource
    {
        $invoice = VendorInvoice::query()->whereKey($vendorInvoice)->firstOrFail();
        Gate::authorize('approve', $invoice);

        return new VendorInvoiceResource($workflow->approve($invoice));
    }

    public function export(
        ExportVendorInvoiceRequest $request,
        string $vendorInvoice,
        VendorInvoiceWorkflow $workflow,
    ): VendorInvoiceResource {
        $invoice = VendorInvoice::query()->whereKey($vendorInvoice)->firstOrFail();
        Gate::authorize('approve', $invoice);

        return new VendorInvoiceResource($workflow->export(
            $invoice,
            (string) $request->validated('idempotency_key'),
        ));
    }
}
