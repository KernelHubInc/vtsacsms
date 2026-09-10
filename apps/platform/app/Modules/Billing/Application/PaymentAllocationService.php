<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\Invoice;
use App\Modules\Billing\Domain\Models\PaymentAllocation;
use App\Modules\Billing\Domain\Models\Receipt;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Payments\Application\Contracts\PaymentFact;
use App\Modules\Payments\Domain\PaymentIntentState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class PaymentAllocationService
{
    public function __construct(private DoubleEntryLedger $ledger, private OutboxRecorder $outbox) {}

    public function allocate(PaymentFact $intent, Invoice $invoice, int $amountMinor): PaymentAllocation
    {
        if (! in_array($intent->state, [PaymentIntentState::Captured, PaymentIntentState::PartiallyCaptured, PaymentIntentState::PartiallyRefunded], true)
            || $intent->currency !== $invoice->currency || $amountMinor <= 0
            || $amountMinor > (int) $invoice->total_minor - (int) $invoice->amount_paid_minor) {
            throw new InvalidArgumentException('Payment cannot be allocated to this invoice.');
        }
        $existing = PaymentAllocation::query()->where('payment_intent_id', $intent->id)->where('invoice_id', $invoice->getKey())->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($intent, $invoice, $amountMinor): PaymentAllocation {
            $allocation = PaymentAllocation::query()->create(['payment_intent_id' => $intent->id, 'invoice_id' => $invoice->getKey(),
                'currency' => $invoice->currency, 'amount_minor' => $amountMinor, 'allocated_at' => now('UTC')]);
            $paid = (int) $invoice->amount_paid_minor + $amountMinor;
            $invoice->forceFill(['amount_paid_minor' => $paid, 'status' => $paid === (int) $invoice->total_minor ? 'paid' : 'partially_paid'])->save();
            Receipt::query()->create(['invoice_id' => $invoice->getKey(), 'payment_intent_id' => $intent->id,
                'document_reference' => 'RCT-'.Str::ulid(), 'currency' => $invoice->currency, 'amount_minor' => $amountMinor,
                'legal_review_required' => true, 'issued_at' => now('UTC')]);
            $clearing = $this->ledger->account('1050-PROVIDER-CLEARING', 'Payment provider clearing', 'asset', (string) $invoice->currency);
            $receivable = $this->ledger->account('1100-AR', 'Accounts receivable', 'asset', (string) $invoice->currency);
            $this->ledger->post('payment_allocation', (string) $allocation->getKey(), 'payment_allocated', (string) $invoice->currency,
                'payment-allocation-'.(string) $allocation->getKey(), [new LedgerPosting($clearing, debitMinor: $amountMinor), new LedgerPosting($receivable, creditMinor: $amountMinor)]);
            $this->outbox->record('billing.payment.allocated.v1', 'payment_allocation', (string) $allocation->getKey(), [
                'payment_intent_id' => $intent->id, 'invoice_id' => (string) $invoice->getKey(),
                'amount_minor' => $amountMinor, 'currency' => $invoice->currency,
            ]);

            return $allocation;
        });
    }
}
