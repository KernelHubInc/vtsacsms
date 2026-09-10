<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Procurement\Domain\Models\ApprovalRule;
use App\Modules\Procurement\Domain\Models\DocumentApproval;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\PurchaseOrderLine;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Procurement\Domain\Models\PurchaseRequestLine;
use App\Modules\Procurement\Domain\Models\QuotationComparison;
use App\Modules\Procurement\Domain\Models\RequestForQuotation;
use App\Modules\Procurement\Domain\Models\Supplier;
use App\Modules\Procurement\Domain\Models\SupplierQuotation;
use App\Modules\Procurement\Domain\Models\SupplierQuotationLine;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Modules\Procurement\Domain\PurchaseRequestStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class SourcingWorkflow
{
    public function __construct(
        private CurrentTenant $tenant,
        private OutboxRecorder $outbox,
        private AuditRecorder $audit,
        private ProcurementNotifier $notifier,
        private ApprovalActorGuard $approvalActors,
    ) {}

    /** @param list<string> $supplierIds */
    public function createRfq(
        PurchaseRequest $request,
        string $rfqNumber,
        array $supplierIds,
        \DateTimeInterface|string $closesAt,
        ?string $instructions = null,
    ): RequestForQuotation {
        return DB::transaction(function () use (
            $request, $rfqNumber, $supplierIds, $closesAt, $instructions,
        ): RequestForQuotation {
            $locked = PurchaseRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseRequestStatus::Approved) {
                throw new DomainException('Only approved purchase requests can enter sourcing.');
            }
            if ($supplierIds === []) {
                throw new DomainException('An RFQ requires at least one invited supplier.');
            }
            $suppliers = Supplier::query()->whereIn('id', array_values(array_unique($supplierIds)))
                ->where('status', 'active')->get();
            if ($suppliers->count() !== count(array_unique($supplierIds))) {
                throw new DomainException('Every invited supplier must be active in this tenant.');
            }

            $rfq = RequestForQuotation::query()->create([
                'purchase_request_id' => $locked->getKey(),
                'rfq_number' => $rfqNumber,
                'status' => 'issued',
                'closes_at' => $closesAt,
                'instructions' => $instructions,
                'created_by' => $this->actorId(),
                'issued_at' => now('UTC'),
            ]);
            foreach ($suppliers as $supplier) {
                DB::table('procurement_rfq_suppliers')->insert([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $this->tenant->get()->tenantId,
                    'rfq_id' => $rfq->getKey(),
                    'supplier_id' => $supplier->getKey(),
                    'status' => 'invited',
                    'invited_at' => now('UTC'),
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            }
            $locked->forceFill(['status' => PurchaseRequestStatus::Sourcing])->save();
            $this->audit->record(new AuditEntry(
                'procurement.rfq.issued',
                'procurement_rfq',
                (string) $rfq->getKey(),
                AuditResult::Succeeded,
                metadata: ['supplier_count' => $suppliers->count()],
            ));

            return $rfq;
        });
    }

    /**
     * @param array{
     *   quotation_reference: string, currency: string, tax_minor?: int, shipping_minor?: int,
     *   lead_time_days?: int|null, valid_until?: string|null, commercial_terms?: array<array-key, mixed>|null,
     *   lines: list<array{
     *     purchase_request_line_id: string, quantity_base: int, unit_price_minor: int,
     *     offered_description?: string|null
     *   }>
     * } $data
     */
    public function recordQuotation(RequestForQuotation $rfq, Supplier $supplier, array $data): SupplierQuotation
    {
        return DB::transaction(function () use ($rfq, $supplier, $data): SupplierQuotation {
            $invited = DB::table('procurement_rfq_suppliers')
                ->where('tenant_id', $this->tenant->get()->tenantId)
                ->where('rfq_id', $rfq->getKey())
                ->where('supplier_id', $supplier->getKey())
                ->lockForUpdate()
                ->first();
            if ($invited === null || $rfq->status !== 'issued') {
                throw new DomainException('The supplier is not invited to an open RFQ.');
            }
            if ($data['lines'] === []) {
                throw new DomainException('A supplier quotation requires at least one line.');
            }

            $subtotal = 0;
            foreach ($data['lines'] as $line) {
                $requestLine = PurchaseRequestLine::query()
                    ->whereKey($line['purchase_request_line_id'])
                    ->where('purchase_request_id', $rfq->purchase_request_id)
                    ->firstOrFail();
                if ($line['quantity_base'] <= 0
                    || $line['quantity_base'] > (int) $requestLine->quantity_base
                    || $line['unit_price_minor'] < 0) {
                    throw new DomainException('Quotation quantities and prices are outside the requested line.');
                }
                $subtotal += $line['quantity_base'] * $line['unit_price_minor'];
            }
            $tax = $data['tax_minor'] ?? 0;
            $shipping = $data['shipping_minor'] ?? 0;
            $quote = SupplierQuotation::query()->create([
                'rfq_id' => $rfq->getKey(),
                'supplier_id' => $supplier->getKey(),
                'quotation_reference' => $data['quotation_reference'],
                'status' => 'submitted',
                'currency' => mb_strtoupper($data['currency']),
                'subtotal_minor' => $subtotal,
                'tax_minor' => $tax,
                'shipping_minor' => $shipping,
                'total_minor' => $subtotal + $tax + $shipping,
                'lead_time_days' => $data['lead_time_days'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'commercial_terms' => $data['commercial_terms'] ?? null,
                'submitted_at' => now('UTC'),
            ]);
            foreach ($data['lines'] as $line) {
                SupplierQuotationLine::query()->create([
                    'supplier_quotation_id' => $quote->getKey(),
                    'purchase_request_line_id' => $line['purchase_request_line_id'],
                    'quantity_base' => $line['quantity_base'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'total_minor' => $line['quantity_base'] * $line['unit_price_minor'],
                    'offered_description' => $line['offered_description'] ?? null,
                ]);
            }
            DB::table('procurement_rfq_suppliers')->where('id', $invited->id)->update([
                'status' => 'responded',
                'responded_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $this->audit->record(new AuditEntry(
                'procurement.quotation.recorded',
                'supplier_quotation',
                (string) $quote->getKey(),
                AuditResult::Succeeded,
                metadata: ['rfq_id' => (string) $rfq->getKey(), 'supplier_id' => (string) $supplier->getKey()],
            ));

            return $quote->load('lines');
        });
    }

    public function prepareComparison(
        RequestForQuotation $rfq,
        SupplierQuotation $selected,
        string $selectionReason,
    ): QuotationComparison {
        return DB::transaction(function () use ($rfq, $selected, $selectionReason): QuotationComparison {
            if ($selected->rfq_id !== $rfq->getKey()) {
                throw new DomainException('The selected quotation does not belong to this RFQ.');
            }
            $quotes = SupplierQuotation::query()->where('rfq_id', $rfq->getKey())
                ->with('lines')->orderBy('total_minor')->get();
            if ($quotes->count() < 1) {
                throw new DomainException('There are no quotations to compare.');
            }
            $snapshot = $quotes->map(static fn (SupplierQuotation $quote): array => [
                'quotation_id' => (string) $quote->getKey(),
                'supplier_id' => (string) $quote->supplier_id,
                'currency' => (string) $quote->currency,
                'total_minor' => (int) $quote->total_minor,
                'lead_time_days' => $quote->lead_time_days === null ? null : (int) $quote->lead_time_days,
                'valid_until' => $quote->valid_until?->format('Y-m-d'),
                'lines' => $quote->lines->map(static fn (SupplierQuotationLine $line): array => [
                    'purchase_request_line_id' => (string) $line->purchase_request_line_id,
                    'quantity_base' => (int) $line->quantity_base,
                    'unit_price_minor' => (int) $line->unit_price_minor,
                    'total_minor' => (int) $line->total_minor,
                ])->all(),
            ])->all();

            return QuotationComparison::query()->updateOrCreate(
                ['rfq_id' => $rfq->getKey()],
                [
                    'selected_quotation_id' => $selected->getKey(),
                    'status' => 'pending_approval',
                    'comparison_snapshot' => $snapshot,
                    'selection_reason' => $selectionReason,
                    'prepared_by' => $this->actorId(),
                    'approved_by' => null,
                    'approved_at' => null,
                ],
            );
        });
    }

    public function approveComparison(QuotationComparison $comparison): QuotationComparison
    {
        if ($comparison->status !== 'pending_approval') {
            throw new DomainException('Only pending quotation comparisons can be approved.');
        }
        $comparison->forceFill([
            'status' => 'approved',
            'approved_by' => $this->actorId(),
            'approved_at' => now('UTC'),
        ])->save();
        $this->audit->record(new AuditEntry(
            'procurement.quotation_comparison.approved',
            'quotation_comparison',
            (string) $comparison->getKey(),
            AuditResult::Succeeded,
        ));

        return $comparison;
    }

    public function createPurchaseOrder(
        QuotationComparison $comparison,
        string $poNumber,
        ?string $deliveryWarehouseId,
        ?string $terms = null,
    ): PurchaseOrder {
        return DB::transaction(function () use ($comparison, $poNumber, $deliveryWarehouseId, $terms): PurchaseOrder {
            if ($comparison->status !== 'approved' || $comparison->selected_quotation_id === null) {
                throw new DomainException('An approved quotation comparison is required.');
            }
            $quote = SupplierQuotation::query()->with('lines')->whereKey($comparison->selected_quotation_id)->firstOrFail();
            $rfq = RequestForQuotation::query()->whereKey($comparison->rfq_id)->firstOrFail();
            $request = PurchaseRequest::query()->whereKey($rfq->purchase_request_id)->lockForUpdate()->firstOrFail();

            $order = PurchaseOrder::query()->create([
                'purchase_request_id' => $request->getKey(),
                'supplier_id' => $quote->supplier_id,
                'selected_quotation_id' => $quote->getKey(),
                'po_number' => $poNumber,
                'status' => PurchaseOrderStatus::Draft,
                'currency' => $quote->currency,
                'subtotal_minor' => $quote->subtotal_minor,
                'tax_minor' => $quote->tax_minor,
                'shipping_minor' => $quote->shipping_minor,
                'total_minor' => $quote->total_minor,
                'delivery_warehouse_id' => $deliveryWarehouseId,
                'terms' => $terms,
                'created_by' => $this->actorId(),
            ]);
            foreach ($quote->lines as $quoteLine) {
                $requestLine = PurchaseRequestLine::query()->whereKey($quoteLine->purchase_request_line_id)->firstOrFail();
                PurchaseOrderLine::query()->create([
                    'purchase_order_id' => $order->getKey(),
                    'purchase_request_line_id' => $requestLine->getKey(),
                    'item_id' => $requestLine->item_id,
                    'uom_id' => $requestLine->uom_id,
                    'description' => $quoteLine->offered_description ?? $requestLine->description,
                    'ordered_quantity_base' => $quoteLine->quantity_base,
                    'unit_price_minor' => $quoteLine->unit_price_minor,
                    'line_total_minor' => $quoteLine->total_minor,
                ]);
            }
            $request->forceFill(['status' => PurchaseRequestStatus::Ordered])->save();
            $this->audit->record(new AuditEntry(
                'procurement.purchase_order.created',
                'purchase_order',
                (string) $order->getKey(),
                AuditResult::Succeeded,
                after: $order->toArray(),
            ));

            return $order->load(['supplier', 'lines']);
        });
    }

    public function submitPurchaseOrder(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseOrderStatus::Draft) {
                throw new DomainException('Only draft purchase orders can be submitted.');
            }
            $rules = ApprovalRule::query()
                ->where('document_type', 'purchase_order')
                ->where('currency', $locked->currency)
                ->where('is_active', true)
                ->where('minimum_amount_minor', '<=', $locked->total_minor)
                ->where(fn ($query) => $query->whereNull('maximum_amount_minor')
                    ->orWhere('maximum_amount_minor', '>=', $locked->total_minor))
                ->orderBy('sequence')
                ->get();
            if ($rules->isEmpty()) {
                throw new DomainException('No approval matrix covers this purchase order.');
            }
            foreach ($rules as $rule) {
                DocumentApproval::query()->create([
                    'document_type' => 'purchase_order',
                    'document_id' => $locked->getKey(),
                    'document_revision' => $locked->revision,
                    'sequence' => $rule->sequence,
                    'approver_role_key' => $rule->approver_role_key,
                    'status' => 'pending',
                ]);
            }
            $firstApproval = DocumentApproval::query()
                ->where('document_type', 'purchase_order')
                ->where('document_id', $locked->getKey())
                ->where('document_revision', $locked->revision)
                ->where('status', 'pending')
                ->orderBy('sequence')
                ->firstOrFail();
            DB::afterCommit(fn () => $this->notifier->approvalRequired(
                $firstApproval,
                (string) $locked->po_number,
            ));
            $locked->forceFill(['status' => PurchaseOrderStatus::Submitted])->save();

            return $locked;
        });
    }

    public function approvePurchaseOrder(PurchaseOrder $order, string $approverRoleKey): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $approverRoleKey): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseOrderStatus::Submitted) {
                throw new DomainException('Only submitted purchase orders can be approved.');
            }
            $approval = DocumentApproval::query()
                ->where('document_type', 'purchase_order')
                ->where('document_id', $locked->getKey())
                ->where('document_revision', $locked->revision)
                ->where('status', 'pending')
                ->orderBy('sequence')
                ->lockForUpdate()
                ->firstOrFail();
            if ($approval->approver_role_key !== $approverRoleKey) {
                throw new DomainException('This approval belongs to a different approval role.');
            }
            $this->approvalActors->assertAssignedRole($approverRoleKey);
            $this->approvalActors->assertDifferentActor((string) $locked->created_by);
            $actorId = $this->actorId();
            $approval->forceFill([
                'status' => 'approved',
                'decided_by' => $actorId,
                'decided_at' => now('UTC'),
            ])->save();
            $pending = DocumentApproval::query()
                ->where('document_type', 'purchase_order')
                ->where('document_id', $locked->getKey())
                ->where('document_revision', $locked->revision)
                ->where('status', 'pending')
                ->exists();
            if (! $pending) {
                $locked->forceFill([
                    'status' => PurchaseOrderStatus::Approved,
                    'approved_by' => $actorId,
                    'approved_at' => now('UTC'),
                ])->save();
            } else {
                $next = DocumentApproval::query()
                    ->where('document_type', 'purchase_order')
                    ->where('document_id', $locked->getKey())
                    ->where('document_revision', $locked->revision)
                    ->where('status', 'pending')
                    ->orderBy('sequence')
                    ->firstOrFail();
                DB::afterCommit(fn () => $this->notifier->approvalRequired(
                    $next,
                    (string) $locked->po_number,
                ));
            }

            return $locked;
        });
    }

    public function issuePurchaseOrder(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseOrderStatus::Approved) {
                throw new DomainException('Only approved purchase orders can be issued.');
            }
            $locked->forceFill(['status' => PurchaseOrderStatus::Issued, 'issued_at' => now('UTC')])->save();
            $this->outbox->record('procurement.purchase_order.issued.v1', 'purchase_order', (string) $locked->getKey(), [
                'po_number' => (string) $locked->po_number,
                'supplier_id' => (string) $locked->supplier_id,
                'currency' => (string) $locked->currency,
                'total_minor' => (int) $locked->total_minor,
            ]);
            $this->audit->record(new AuditEntry(
                'procurement.purchase_order.issued',
                'purchase_order',
                (string) $locked->getKey(),
                AuditResult::Succeeded,
            ));

            return $locked;
        });
    }

    private function actorId(): string
    {
        return $this->tenant->get()->actorId
            ?? throw new DomainException('Procurement changes require an accountable actor.');
    }
}
