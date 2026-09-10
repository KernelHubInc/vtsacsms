<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Procurement\Application\Contracts\VendorInvoiceAccountingAdapter;
use App\Modules\Procurement\Domain\Models\ProcurementDiscrepancy;
use App\Modules\Procurement\Domain\Models\PurchaseOrder;
use App\Modules\Procurement\Domain\Models\PurchaseOrderLine;
use App\Modules\Procurement\Domain\Models\ThreeWayMatch;
use App\Modules\Procurement\Domain\Models\VendorInvoice;
use App\Modules\Procurement\Domain\Models\VendorInvoiceLine;
use App\Modules\Procurement\Domain\VendorInvoiceStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class VendorInvoiceWorkflow
{
    public function __construct(
        private CurrentTenant $tenant,
        private VendorInvoiceAccountingAdapter $accounting,
        private OutboxRecorder $outbox,
        private AuditRecorder $audit,
        private ProcurementNotifier $notifier,
    ) {}

    /**
     * @param array{
     *   invoice_reference: string, currency: string, tax_minor?: int,
     *   invoice_date: string, due_date?: string|null,
     *   lines: list<array{purchase_order_line_id: string, quantity_base: int, unit_price_minor: int}>
     * } $data
     */
    public function create(PurchaseOrder $order, array $data): VendorInvoice
    {
        return DB::transaction(function () use ($order, $data): VendorInvoice {
            $lockedOrder = PurchaseOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($data['lines'] === [] || mb_strtoupper($data['currency']) !== $lockedOrder->currency) {
                throw new DomainException('The vendor invoice requires lines in the purchase-order currency.');
            }
            $subtotal = 0;
            foreach ($data['lines'] as $line) {
                $poLine = PurchaseOrderLine::query()
                    ->whereKey($line['purchase_order_line_id'])
                    ->where('purchase_order_id', $lockedOrder->getKey())
                    ->firstOrFail();
                if ($line['quantity_base'] <= 0
                    || $line['quantity_base'] > (int) $poLine->ordered_quantity_base
                    || $line['unit_price_minor'] < 0) {
                    throw new DomainException('Vendor-invoice quantities and prices must be valid non-negative integers.');
                }
                $subtotal += $line['quantity_base'] * $line['unit_price_minor'];
            }
            $tax = $data['tax_minor'] ?? 0;
            $invoice = VendorInvoice::query()->create([
                'supplier_id' => $lockedOrder->supplier_id,
                'purchase_order_id' => $lockedOrder->getKey(),
                'invoice_reference' => $data['invoice_reference'],
                'status' => VendorInvoiceStatus::Submitted,
                'currency' => $lockedOrder->currency,
                'subtotal_minor' => $subtotal,
                'tax_minor' => $tax,
                'total_minor' => $subtotal + $tax,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'accounting_export_state' => 'not_ready',
            ]);
            foreach ($data['lines'] as $line) {
                VendorInvoiceLine::query()->create([
                    'vendor_invoice_id' => $invoice->getKey(),
                    'purchase_order_line_id' => $line['purchase_order_line_id'],
                    'quantity_base' => $line['quantity_base'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'total_minor' => $line['quantity_base'] * $line['unit_price_minor'],
                ]);
            }
            $this->audit->record(new AuditEntry(
                'procurement.vendor_invoice.created',
                'vendor_invoice',
                (string) $invoice->getKey(),
                AuditResult::Succeeded,
                after: $invoice->toArray(),
            ));

            return $invoice->load('lines');
        });
    }

    public function match(VendorInvoice $invoice, int $amountToleranceMinor = 0): ThreeWayMatch
    {
        if ($amountToleranceMinor < 0) {
            throw new DomainException('Match tolerance cannot be negative.');
        }

        return DB::transaction(function () use ($invoice, $amountToleranceMinor): ThreeWayMatch {
            $locked = VendorInvoice::query()->with('lines')->whereKey($invoice->getKey())
                ->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [
                VendorInvoiceStatus::Submitted,
                VendorInvoiceStatus::Discrepancy,
            ], true)) {
                throw new DomainException('This vendor invoice cannot be matched again.');
            }
            $order = PurchaseOrder::query()->with('lines')->whereKey($locked->purchase_order_id)->firstOrFail();
            $issues = [];
            $expectedSubtotal = 0;
            $invoicedQuantity = 0;
            $acceptedQuantity = 0;
            foreach ($locked->lines as $invoiceLine) {
                $poLine = $order->lines->firstWhere('id', $invoiceLine->purchase_order_line_id);
                if (! $poLine instanceof PurchaseOrderLine) {
                    $issues[] = ['type' => 'line_not_on_po', 'line_id' => (string) $invoiceLine->getKey()];

                    continue;
                }
                $invoicedQuantity += (int) $invoiceLine->quantity_base;
                $acceptedQuantity += min((int) $poLine->accepted_quantity_base, (int) $invoiceLine->quantity_base);
                $expectedSubtotal += (int) $invoiceLine->quantity_base * (int) $poLine->unit_price_minor;
                if ((int) $invoiceLine->quantity_base > (int) $poLine->accepted_quantity_base) {
                    $issues[] = [
                        'type' => 'quantity_exceeds_accepted',
                        'line_id' => (string) $invoiceLine->getKey(),
                        'invoiced_quantity_base' => (int) $invoiceLine->quantity_base,
                        'accepted_quantity_base' => (int) $poLine->accepted_quantity_base,
                    ];
                }
                if ((int) $invoiceLine->unit_price_minor !== (int) $poLine->unit_price_minor) {
                    $issues[] = [
                        'type' => 'unit_price_mismatch',
                        'line_id' => (string) $invoiceLine->getKey(),
                        'invoice_unit_minor' => (int) $invoiceLine->unit_price_minor,
                        'po_unit_minor' => (int) $poLine->unit_price_minor,
                    ];
                }
            }
            $variance = (int) $locked->subtotal_minor - $expectedSubtotal;
            if (abs($variance) > $amountToleranceMinor) {
                $issues[] = [
                    'type' => 'amount_variance',
                    'expected_subtotal_minor' => $expectedSubtotal,
                    'invoice_subtotal_minor' => (int) $locked->subtotal_minor,
                    'variance_minor' => $variance,
                    'tolerance_minor' => $amountToleranceMinor,
                ];
            }
            if ($locked->currency !== $order->currency) {
                $issues[] = ['type' => 'currency_mismatch'];
            }

            $status = $issues === [] ? 'matched' : 'discrepancy';
            $previousMatchIds = ThreeWayMatch::query()
                ->where('vendor_invoice_id', $locked->getKey())
                ->pluck('id');
            ProcurementDiscrepancy::query()->whereIn('three_way_match_id', $previousMatchIds)
                ->where('status', 'open')->update(['status' => 'superseded', 'resolved_at' => now('UTC')]);
            $match = ThreeWayMatch::query()->create([
                'vendor_invoice_id' => $locked->getKey(),
                'purchase_order_id' => $order->getKey(),
                'status' => $status,
                'po_total_minor' => $expectedSubtotal,
                'invoice_total_minor' => $locked->subtotal_minor,
                'amount_variance_minor' => $variance,
                'ordered_quantity_base' => (int) $order->lines->sum('ordered_quantity_base'),
                'accepted_quantity_base' => $acceptedQuantity,
                'invoiced_quantity_base' => $invoicedQuantity,
                'evidence_snapshot' => [
                    'purchase_order_revision' => (int) $order->revision,
                    'amount_tolerance_minor' => $amountToleranceMinor,
                    'issues' => $issues,
                ],
                'matched_by' => $this->actorId(),
                'matched_at' => now('UTC'),
            ]);
            foreach ($issues as $issue) {
                ProcurementDiscrepancy::query()->create([
                    'three_way_match_id' => $match->getKey(),
                    'type' => $issue['type'],
                    'status' => 'open',
                    'severity' => 'warning',
                    'description' => str_replace('_', ' ', ucfirst((string) $issue['type'])),
                    'evidence' => $issue,
                ]);
            }
            $locked->forceFill([
                'status' => $status === 'matched' ? VendorInvoiceStatus::Matched : VendorInvoiceStatus::Discrepancy,
                'accounting_export_state' => $status === 'matched' ? 'ready' : 'blocked',
                'matched_at' => now('UTC'),
            ])->save();
            if ($issues !== []) {
                DB::afterCommit(fn () => $this->notifier->discrepancyOpened(
                    (string) $locked->getKey(),
                    (string) $locked->invoice_reference,
                    count($issues),
                ));
            }
            $this->audit->record(new AuditEntry(
                'procurement.vendor_invoice.matched',
                'vendor_invoice',
                (string) $locked->getKey(),
                AuditResult::Succeeded,
                metadata: ['match_id' => (string) $match->getKey(), 'status' => $status],
            ));

            return $match->load('discrepancies');
        });
    }

    public function approve(VendorInvoice $invoice): VendorInvoice
    {
        if ($invoice->status !== VendorInvoiceStatus::Matched) {
            throw new DomainException('Only a successfully matched vendor invoice can be approved.');
        }
        $invoice->forceFill([
            'status' => VendorInvoiceStatus::Approved,
            'accounting_export_state' => 'ready',
            'approved_at' => now('UTC'),
        ])->save();
        $this->audit->record(new AuditEntry(
            'procurement.vendor_invoice.approved',
            'vendor_invoice',
            (string) $invoice->getKey(),
            AuditResult::Succeeded,
        ));

        return $invoice;
    }

    public function export(VendorInvoice $invoice, string $idempotencyKey): VendorInvoice
    {
        if ($invoice->status !== VendorInvoiceStatus::Approved) {
            throw new DomainException('Only approved vendor invoices can be exported.');
        }
        $result = $this->accounting->export($invoice, $idempotencyKey);
        $invoice->forceFill([
            'status' => VendorInvoiceStatus::Exported,
            'accounting_export_state' => 'exported',
            'exported_at' => $result['exported_at'],
        ])->save();
        $this->outbox->record('procurement.vendor_invoice.exported.v1', 'vendor_invoice', (string) $invoice->getKey(), [
            'external_reference' => $result['external_reference'],
        ]);

        return $invoice;
    }

    private function actorId(): string
    {
        return $this->tenant->get()->actorId
            ?? throw new DomainException('Procurement changes require an accountable actor.');
    }
}
