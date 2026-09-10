<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\BillingProfile;
use App\Modules\Billing\Domain\Models\Invoice;
use App\Modules\Billing\Domain\Models\InvoiceLine;
use App\Modules\Billing\Domain\Models\RatedCharge;
use App\Modules\Charging\Application\Contracts\FinalizedChargeDetailRecordQuery;
use App\Modules\Integrations\Application\OutboxRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class InvoiceService
{
    public function __construct(private FinalizedChargeDetailRecordQuery $cdrs, private DoubleEntryLedger $ledger, private OutboxRecorder $outbox) {}

    public function issueForChargeDetailRecord(string $chargeDetailRecordId, BillingProfile $profile): Invoice
    {
        $cdr = $this->cdrs->get($chargeDetailRecordId);
        $existing = Invoice::query()->whereHas('lines', fn ($query) => $query->whereHas('ratedCharge', fn ($charge) => $charge->where('charge_detail_record_id', $cdr->id)))->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($cdr, $profile): Invoice {
            $rated = RatedCharge::query()->firstOrCreate(['charge_detail_record_id' => $cdr->id], [
                'charging_session_id' => $cdr->sessionId, 'cdr_version' => $cdr->version, 'cdr_snapshot_hash' => $cdr->snapshotHash,
                'currency' => $cdr->currency, 'subtotal_minor' => $cdr->subtotalMinor, 'discount_minor' => $cdr->discountMinor,
                'tax_minor' => $cdr->taxMinor, 'total_minor' => $cdr->totalMinor, 'source_snapshot' => $cdr->sourceSnapshot,
                'finalized_at' => $cdr->finalizedAt,
            ]);
            $invoice = Invoice::query()->create(['billing_profile_id' => $profile->getKey(), 'document_reference' => 'INV-'.Str::ulid(),
                'status' => 'issued', 'currency' => $cdr->currency, 'subtotal_minor' => $cdr->subtotalMinor,
                'discount_minor' => $cdr->discountMinor, 'tax_minor' => $cdr->taxMinor, 'total_minor' => $cdr->totalMinor,
                'amount_paid_minor' => 0, 'legal_review_required' => true, 'issued_at' => now('UTC')]);
            InvoiceLine::query()->create(['invoice_id' => $invoice->getKey(), 'rated_charge_id' => $rated->getKey(),
                'description' => 'EV charging session', 'quantity' => 1, 'unit_amount_minor' => $cdr->subtotalMinor,
                'subtotal_minor' => $cdr->subtotalMinor, 'discount_minor' => $cdr->discountMinor, 'tax_minor' => $cdr->taxMinor,
                'total_minor' => $cdr->totalMinor, 'tax_snapshot' => ['source' => 'immutable_tariff_snapshot', 'legal_review_required' => true]]);
            $receivable = $this->ledger->account('1100-AR', 'Accounts receivable', 'asset', $cdr->currency);
            $revenue = $this->ledger->account('4000-CHARGING-GROSS', 'Gross charging revenue', 'revenue', $cdr->currency);
            $this->ledger->post('invoice', (string) $invoice->getKey(), 'invoice_issued', $cdr->currency,
                'invoice-issued-'.(string) $invoice->getKey(), [new LedgerPosting($receivable, debitMinor: $cdr->totalMinor), new LedgerPosting($revenue, creditMinor: $cdr->totalMinor)]);
            $this->outbox->record('billing.invoice.issued.v1', 'invoice', (string) $invoice->getKey(), [
                'document_reference' => $invoice->document_reference, 'total_minor' => $cdr->totalMinor,
                'currency' => $cdr->currency, 'legal_review_required' => true,
            ]);

            return $invoice;
        });
    }
}
